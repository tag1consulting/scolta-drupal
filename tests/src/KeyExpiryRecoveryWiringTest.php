<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\Cache\DrupalCacheDriver;
use Drupal\scolta\Service\ScoltaAiService;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Health\HealthChecker;

/**
 * Behavioral coverage for the Amazee key-expiry recovery wiring.
 *
 * Regression (django demo, 2026-06-09): an Amazee trial key expired
 * server-side, every AI call returned 400 expired_key, and the adapter
 * neither recovered nor reported the truth — expand echoed the query while
 * /health still claimed `ai_configured: true`. ScoltaAiService now wires
 * KeyExpiryRecovery (re-provision + one retry) on the auto-provisioned path
 * and HealthController hands the same cache to HealthChecker.
 *
 * scolta-php's AiServiceAdapterTest proves the base recover-and-retry loop;
 * these tests prove the Drupal-specific wiring: recovery fires only on the
 * Amazee path, the retry uses Drupal's HTTP client, the Drupal cache bridge
 * satisfies KeyExpiryRecovery's marker contract, and health stays truthful.
 */
class KeyExpiryRecoveryWiringTest extends TestCase {

  private const FRESH_TRIAL_RESPONSE = '{"key": {"litellm_token": "sk-fresh-token", "litellm_api_url": "https://llm.test.amazee.ai", "region": "test-region"}}';
  private const MODEL_INFO_RESPONSE = '{"data": [{"model_name": "claude-sonnet-4-5"}, {"model_name": "claude-haiku-4-5"}]}';
  private const EXPIRED_KEY_BODY = '{"error": {"code": "expired_key", "message": "Authentication Error - Expired Key"}}';
  private const CHAT_SUCCESS_BODY = '{"choices": [{"message": {"content": "recovered response"}}]}';

  private const EXPIRED_CREDS = [
    'litellm_token' => 'sk-expired-token',
    'litellm_api_url' => 'https://llm.test.amazee.ai',
    'region' => 'test-region',
  ];

  // -------------------------------------------------------------------
  // Health truthfulness through the Drupal cache bridge
  // -------------------------------------------------------------------

  public function testHealthReportsAuthFailingWhenMarkerSet(): void {
    $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());

    // Record an auth failure exactly as a failing AI call would.
    $recovery = new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::EXPIRED_CREDS), $driver);
    $recovery->recordAuthFailure();

    $result = $this->runHealthCheck($driver);

    $this->assertTrue($result['ai_configured'], 'Credentials are still present');
    $this->assertTrue($result['ai_auth_failing'], 'The recorded marker must surface');
    $this->assertFalse($result['ai_usable'], 'Known-expired credentials must not report usable');
    $this->assertSame('degraded', $result['status']);
  }

  public function testHealthReportsUsableWhenNoMarker(): void {
    $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());

    $result = $this->runHealthCheck($driver);

    $this->assertTrue($result['ai_configured']);
    $this->assertFalse($result['ai_auth_failing']);
    $this->assertTrue($result['ai_usable'], 'A configured, non-failing key is usable');
  }

  public function testHealthCheckerWithoutCacheStillMirrorsConfigured(): void {
    // Null cache (recovery not wired) preserves the pre-recovery behavior:
    // ai_usable simply mirrors ai_configured.
    $result = $this->runHealthCheck(NULL);

    $this->assertTrue($result['ai_configured']);
    $this->assertFalse($result['ai_auth_failing']);
    $this->assertTrue($result['ai_usable']);
  }

  // -------------------------------------------------------------------
  // Recovery once-per-window through the Drupal cache bridge
  // -------------------------------------------------------------------

  public function testRecoveryReprovisionsOncePerWindowThroughDrupalBridge(): void {
    $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());
    $storage = new InMemoryRecoveryStorage(self::EXPIRED_CREDS);
    $recovery = new KeyExpiryRecovery(
      storage: $storage,
      cache: $driver,
      client: $this->makeAmazeeClient([
        new Response(200, [], self::FRESH_TRIAL_RESPONSE),
        new Response(200, [], self::MODEL_INFO_RESPONSE),
      ], $mock),
    );

    $first = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

    $this->assertTrue($first, 'An expired key triggers a re-provision');
    $this->assertSame('sk-fresh-token', $recovery->credentials()['litellm_token'], 'Fresh credentials stored');
    $this->assertFalse($recovery->isAuthFailing(), 'Successful recovery clears the marker via the Drupal cache');
    $this->assertSame(0, $mock->count(), 'Both provisioning calls (trial + models) ran');

    // A second failure inside the window must not hit the provisioning API
    // again — the MockHandler queue is empty, so any call would throw.
    $second = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));
    $this->assertFalse($second, 'The window guard (read through the Drupal cache) blocks a second attempt');
  }

  // -------------------------------------------------------------------
  // ScoltaAiService end-to-end: recovery wired only on the Amazee path
  // -------------------------------------------------------------------

  public function testServiceRecoversAndRetriesViaDrupalHttpClientOnAmazeePath(): void {
    // Stored (expired) Amazee credentials, no explicit key → the recovery path.
    $httpMock = new MockHandler([
      new Response(400, [], self::EXPIRED_KEY_BODY),
      new Response(200, [], self::CHAT_SUCCESS_BODY),
    ]);
    $storage = new InMemoryRecoveryStorage(self::EXPIRED_CREDS);
    $service = $this->makeProbe(
      amazee: $this->makeAmazeeClient([
        new Response(200, [], self::FRESH_TRIAL_RESPONSE),
        new Response(200, [], self::MODEL_INFO_RESPONSE),
      ], $amazeeMock),
      httpClient: new Client(['handler' => HandlerStack::create($httpMock)]),
      stateCreds: self::EXPIRED_CREDS,
      settings: [],
      storage: $storage,
    );

    $result = $service->message('system', 'user message');

    $this->assertSame('recovered response', $result, 'The retry served the recovered client');
    $this->assertSame('sk-fresh-token', $storage->load()['litellm_token'], 'Fresh credentials persisted');
    $this->assertSame(0, $amazeeMock->count(), 'Re-provision attempted exactly once');
    $this->assertSame(0, $httpMock->count(), 'Both the failing call and the recovered retry went through Drupal\'s HTTP client');
  }

  public function testServiceDoesNotWireRecoveryForExplicitKey(): void {
    // An explicit SCOLTA_API_KEY failing auth is the user's to fix — the
    // adapter must not silently re-provision an Amazee trial behind it.
    $httpMock = new MockHandler([new Response(400, [], self::EXPIRED_KEY_BODY)]);
    $service = $this->makeProbe(
      amazee: $this->makeAmazeeClient([], $amazeeMock),
      httpClient: new Client(['handler' => HandlerStack::create($httpMock)]),
      stateCreds: NULL,
      settings: ['scolta.api_key' => 'sk-explicit-user-key'],
      storage: new InMemoryRecoveryStorage(NULL),
    );

    try {
      $service->message('system', 'user message');
      $this->fail('Expected the auth failure to propagate unchanged');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('expired_key', $e->getMessage());
    }

    $this->assertSame(0, $amazeeMock->count(), 'No provisioning attempt for an explicit key');
  }

  // -------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------

  /**
   * Run a HealthChecker for a configured Amazee install with the given cache.
   */
  private function runHealthCheck(?CacheDriverInterface $cache): array {
    $config = ScoltaConfig::fromArray([
      'ai_provider' => 'openai',
      'ai_api_key' => 'sk-amazee-litellm-token',
    ]);
    $checker = new HealthChecker(
      config: $config,
      indexOutputDir: sys_get_temp_dir(),
      pagefindBinaryPath: NULL,
      projectDir: NULL,
      cache: $cache,
    );

    return $checker->check();
  }

  /**
   * Build an AmazeeClient backed by a MockHandler queue.
   */
  private function makeAmazeeClient(array $responses, ?MockHandler &$mock = NULL): AmazeeClient {
    $mock = new MockHandler($responses);
    return new AmazeeClient(
      'https://api.amazee.ai',
      new Client(['handler' => HandlerStack::create($mock)]),
    );
  }

  /**
   * Construct a ScoltaAiService whose KeyExpiryRecovery uses a stubbed
   * AmazeeClient, with Drupal services mocked (no bootstrap).
   */
  private function makeProbe(
    AmazeeClient $amazee,
    ClientInterface $httpClient,
    ?array $stateCreds,
    array $settings,
    InMemoryRecoveryStorage $storage,
  ): RecoveryProbeService {
    new Settings($settings + ['hash_salt' => 'test-salt']);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn ($key = '') => $key === 'name' ? 'Test Site' : []
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      static fn ($key, $default = NULL) => $key === 'scolta.amazee.credentials' ? $stateCreds : $default
    );

    return new RecoveryProbeService(
      $amazee,
      $httpClient,
      $configFactory,
      new NullLogger(),
      $state,
      $storage,
      new InMemoryRecoveryCacheBackend(),
    );
  }

}

/**
 * ScoltaAiService variant that injects a stubbed AmazeeClient into recovery.
 *
 * The control-plane client is the only piece that must be faked for an
 * offline test; everything else (path gating, createRecoveredClient, the
 * base recover-and-retry loop) runs as in production.
 */
class RecoveryProbeService extends ScoltaAiService {

  private AmazeeClient $injectedAmazeeClient;

  public function __construct(
    AmazeeClient $amazee,
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    LoggerInterface $logger,
    StateInterface $state,
    ConfigStorageInterface $storage,
    CacheBackendInterface $cache,
  ) {
    // Assigned before parent::__construct so createKeyExpiryRecovery(), called
    // from the parent constructor's wiring, sees the stubbed client.
    $this->injectedAmazeeClient = $amazee;
    parent::__construct($httpClient, $configFactory, $logger, $state, NULL, $storage, NULL, $cache);
  }

  protected function createKeyExpiryRecovery(CacheDriverInterface $cache): KeyExpiryRecovery {
    return new KeyExpiryRecovery(
      storage: $this->amazeeConfigStorage,
      cache: $cache,
      client: $this->injectedAmazeeClient,
    );
  }

}

/**
 * Minimal in-memory ConfigStorageInterface for the recovery store.
 */
class InMemoryRecoveryStorage implements ConfigStorageInterface {

  public function __construct(private ?array $stored = NULL) {}

  public function store(string $litellmToken, string $litellmApiUrl, string $region): void {
    $this->stored = [
      'litellm_token' => $litellmToken,
      'litellm_api_url' => $litellmApiUrl,
      'region' => $region,
    ];
  }

  public function load(): ?array {
    return $this->stored;
  }

  public function clear(): void {
    $this->stored = NULL;
  }

}

/**
 * Minimal in-memory Drupal cache backend (TTL ignored — tests run in-window).
 *
 * Mirrors the shape DrupalCacheDriver expects: get() returns a stdClass with a
 * ->data property, or false on a miss.
 */
class InMemoryRecoveryCacheBackend implements CacheBackendInterface {

  /** @var array<string, mixed> */
  private array $store = [];

  public function get($cid, $allow_invalid = FALSE): object|false {
    if (!array_key_exists($cid, $this->store)) {
      return FALSE;
    }
    $item = new \stdClass();
    $item->data = $this->store[$cid];
    return $item;
  }

  public function set($cid, $data, $expire = -1, array $tags = []): void {
    $this->store[$cid] = $data;
  }

  public function delete($cid): void {
    unset($this->store[$cid]);
  }

  public function deleteAll(): void {
    $this->store = [];
  }

  public function getMultiple(&$cids, $allow_invalid = FALSE): array {
    $result = [];
    foreach ($cids as $key => $cid) {
      $item = $this->get($cid);
      if ($item !== FALSE) {
        $result[$cid] = $item;
        unset($cids[$key]);
      }
    }
    return $result;
  }

  public function setMultiple(array $items): void {
    foreach ($items as $item) {
      $this->set($item['cid'], $item['data'], $item['expire'] ?? -1, $item['tags'] ?? []);
    }
  }

  public function deleteMultiple(array $cids): void {
    foreach ($cids as $cid) {
      $this->delete($cid);
    }
  }

  public function invalidate($cid): void {}

  public function invalidateMultiple(array $cids): void {}

  public function invalidateAll(): void {}

  public function garbageCollection(): void {}

  public function removeBin(): void {}

}
