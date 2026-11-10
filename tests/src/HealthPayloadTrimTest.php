<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta_ui\Controller\HealthController;
use Drupal\scolta_ui\Service\AiOrigin;
use Drupal\scolta_ui\Service\IndexOrigin;
use Drupal\scolta_ui\Service\ScoltaAiService;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Tag1\Scolta\Index\RemoteIndexLocator;
use Tag1\Scolta\Config\ApiKeySource;
use Tag1\Scolta\Config\ResolvedApiKey;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Behavioral tests for the health endpoint's permission-trimmed payload.
 *
 * Policy: the health route stays reachable anonymously so uptime monitors
 * always work, but the full diagnostic payload (provider, index integrity,
 * fragment counts) requires 'administer scolta ui'. Anonymous callers receive
 * exactly ['status' => ...].
 *
 * The real HealthController is constructed with stubbed services and a real
 * IndexOrigin over a temp directory; currentUser() and config() resolve
 * through a minimal \Drupal container installed per test.
 */
class HealthPayloadTrimTest extends TestCase {

  private string $moduleRoot;

  /**
   * The request time of the default per-controller cache.
   */
  private int $now = 0;

  /**
   * The key resolution this site's AI service reports.
   */
  private ?ResolvedApiKey $resolvedKey = NULL;

  /**
   * Temp directory serving as the pagefind output dir.
   */
  private string $dir;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->dir = sys_get_temp_dir() . '/scolta-health-' . uniqid();
    mkdir($this->dir, 0777, TRUE);
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->dir);
  }

  // -------------------------------------------------------------------
  // Routing: the health route must be anonymously reachable.
  // -------------------------------------------------------------------

  public function testHealthRouteIsAnonymouslyReachable(): void {
    $routing = PackageManifest::routes();

    $this->assertSame(
      'TRUE',
      $routing['scolta.health']['requirements']['_access'] ?? NULL,
      'Health route must use _access TRUE so monitors work without a permission grant'
    );
    $this->assertArrayNotHasKey(
      '_permission',
      $routing['scolta.health']['requirements'],
      'Health route must not require a permission — the controller trims the payload instead'
    );
  }

  // -------------------------------------------------------------------
  // Behavioral: the controller trims by the caller's permission.
  // -------------------------------------------------------------------

  /**
   * Build a real HealthController and install its \Drupal container.
   *
   * @param bool $isAdmin
   *   Whether the current user has 'administer scolta ui'.
   * @param string|null $indexOrigin
   *   The index_origin setting; NULL searches the local index.
   * @param int $remoteStatus
   *   The HTTP status the remote origin answers its entry file with.
   * @param \GuzzleHttp\ClientInterface|null $httpClient
   *   A client to use instead of the stub answering $remoteStatus.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   The cache backend; NULL leaves the controller without one.
   * @param string|null $aiOrigin
   *   The ai_origin setting; NULL answers AI requests here.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The health request being answered.
   */
  private function createController(bool $isAdmin, ?string $indexOrigin = NULL, int $remoteStatus = 200, ?ClientInterface $httpClient = NULL, ?CacheBackendInterface $cache = NULL, ?string $aiOrigin = NULL, ?Request $request = NULL): HealthController {
    // One stub answers for both settings objects: the keys do not overlap.
    $settings = $this->createStub(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      // A plain filesystem path, so the stream wrapper manager is never
      // consulted and no bootstrap is needed to resolve it.
      'pagefind.output_dir' => $this->dir,
      'index_origin' => $indexOrigin,
      'ai_origin' => $aiOrigin,
      default => NULL,
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settings);

    $account = $this->createStub(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      fn (string $permission) => $isAdmin && $permission === 'administer scolta ui'
    );

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('current_user', $account);
    \Drupal::setContainer($container);

    $aiService = $this->createStub(ScoltaAiService::class);
    $aiService->method('getConfig')->willReturn(new ScoltaConfig());
    $aiService->method('hasDrupalAiModule')->willReturn(FALSE);
    $aiService->method('resolveApiKey')->willReturn(
      $this->resolvedKey ?? new ResolvedApiKey('', ApiKeySource::None, '')
    );

    if ($httpClient === NULL) {
      $httpClient = $this->createStub(ClientInterface::class);
      $httpClient->method('request')->willReturnCallback(
        fn (string $method, string $uri) => $this->remoteAnswer($uri, $remoteStatus)
      );
    }
    // A remote origin's locator requires one; a fresh one per controller
    // shares nothing between calls.
    $cache ??= $this->memoryCache($this->now);

    $requests = new RequestStack();
    $requests->push($request ?? Request::create('/api/scolta/v1/health'));

    return new HealthController(
      $aiService,
      new IndexOrigin($configFactory, $this->createStub(StreamWrapperManagerInterface::class), $httpClient, $cache),
      $httpClient,
      $cache,
      new AiOrigin($configFactory),
      $requests,
    );
  }

  /**
   * Write a valid index (pagefind.js plus fragments) into the temp dir.
   */
  private function buildIndex(int $fragments): void {
    mkdir($this->dir . '/pagefind/fragment', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'js');
    for ($i = 0; $i < $fragments; $i++) {
      file_put_contents($this->dir . "/pagefind/fragment/en_{$i}.pf_fragment", 'x');
    }
  }

  public function testAnonymousPayloadContainsExactlyStatus(): void {
    $this->buildIndex(2);
    $response = $this->createController(FALSE)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(['status'], array_keys($payload), 'Anonymous callers must receive exactly the status key');
    $this->assertIsString($payload['status']);
  }

  public function testAnonymousPayloadStillReflectsDegradedStatus(): void {
    // An index whose fragment directory is empty is degraded: pagefind.js
    // exists but there is nothing to search.
    mkdir($this->dir . '/pagefind', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'js');

    $response = $this->createController(FALSE)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('degraded', $payload['status'] ?? NULL, 'Integrity degradation must survive the anonymous trim');
    $this->assertArrayHasKey('status', $payload, 'Anonymous payload must contain status');
  }

  public function testAdminPayloadContainsFullDetail(): void {
    $this->buildIndex(2);
    $response = $this->createController(TRUE)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    foreach (['status', 'ai_provider', 'ai_configured', 'index_exists', 'index'] as $key) {
      $this->assertArrayHasKey($key, $payload, "Admin payload missing {$key}");
    }
    $this->assertTrue($payload['index_exists']);
    $this->assertTrue($payload['index']['built']);
    $this->assertSame(2, $payload['index']['fragments'], 'Fragment count must come from the real index on disk');
    $this->assertTrue($payload['index']['integrity']['valid']);
    $this->assertSame([], $payload['index']['integrity']['issues']);
    $this->assertNotNull($payload['index']['last_build']);
    $this->assertSame([], $payload['status_reasons'], 'A healthy index must leave status_reasons empty');
  }

  public function testAdminPayloadReportsMissingIndex(): void {
    $response = $this->createController(TRUE)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($payload['index_exists']);
    $this->assertSame(['built' => FALSE], $payload['index']);
  }

  public function testAdminPayloadFlagsEmptyFragmentDirAsInvalid(): void {
    mkdir($this->dir . '/pagefind', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'js');

    $response = $this->createController(TRUE)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('degraded', $payload['status']);
    $this->assertFalse($payload['index']['integrity']['valid']);
    $this->assertContains('No fragment files found', $payload['index']['integrity']['issues']);
    $this->assertArrayHasKey('status_reasons', $payload);
    $this->assertContains(HealthController::REASON_INDEX_INTEGRITY_INVALID, $payload['status_reasons']);
  }

  // -------------------------------------------------------------------
  // Remote origin: the local directory's faults do not apply.
  // -------------------------------------------------------------------

  public function testRemoteOriginIsNotDegradedByTheEmptyLocalDirectory(): void {
    // Nothing is written to the local output dir: a thin consumer has none.
    $response = $this->createController(TRUE, 'https://index.example.com', 200)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('ok', $payload['status'], 'A reachable remote index must not report the missing local one');
    $this->assertNotContains('index_missing', $payload['status_reasons']);
    $this->assertSame('https://index.example.com', $payload['index_origin']);
    $this->assertSame(['built' => TRUE, 'remote' => TRUE], $payload['index']);
  }

  public function testUnreachableRemoteOriginDegradesWithItsOwnReason(): void {
    $response = $this->createController(TRUE, 'https://index.example.com', 404)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('degraded', $payload['status']);
    $this->assertSame(['remote_index_unreachable'], $payload['status_reasons']);
    $this->assertFalse($payload['index_exists']);
  }

  public function testUnreachableRemoteOriginDegradesTheAnonymousStatusToo(): void {
    $response = $this->createController(FALSE, 'https://index.example.com', 404)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(['status' => 'degraded'], $payload);
  }

  // -------------------------------------------------------------------
  // The remote index check is reused, so /health cannot pile up requests.
  // -------------------------------------------------------------------

  /**
   * An in-memory cache whose entries expire against a clock the test moves.
   *
   * A mock rather than core's MemoryBackend: the unit job runs without
   * drupal/core, where only the CacheBackendInterface stub exists.
   */
  private function memoryCache(int &$now): CacheBackendInterface {
    $store = new \ArrayObject();
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(function ($cid) use ($store, &$now) {
      if (!isset($store[$cid])) {
        return FALSE;
      }
      [$data, $expire] = $store[$cid];
      if ($expire !== -1 && $expire < $now) {
        return FALSE;
      }
      return (object) ['data' => $data];
    });
    $cache->method('set')->willReturnCallback(function ($cid, $data, $expire = -1) use ($store): void {
      $store[$cid] = [$data, $expire];
    });
    return $cache;
  }

  /**
   * Checks of the remote origin, from a client that counts its requests.
   */

  /**
   * What a remote index host answers for one of its files.
   *
   * At 200 it serves an index: pagefind.js as a script and an entry file
   * with the version and languages scolta-php's locator requires. Any other
   * status is answered for every file.
   */
  private function remoteAnswer(string $uri, int $status): Response {
    if ($status !== 200) {
      return new Response($status);
    }
    return str_ends_with($uri, '/pagefind-entry.json')
      ? new Response(200, ['Content-Type' => 'application/json'], '{"version":"1.4.0","languages":{"en":{}}}')
      : new Response(200, ['Content-Type' => 'text/javascript'], 'export {};');
  }

  /**
   * Checks of the remote origin, from a client that counts its requests.
   *
   * A check of a served index makes two requests (the script, then the entry
   * file); a failed one stops at the first.
   */
  private function countingClient(int $status, int $expectedRequests): ClientInterface {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->exactly($expectedRequests))
      ->method('request')
      ->willReturnCallback(fn (string $method, string $uri) => $this->remoteAnswer($uri, $status));
    return $client;
  }

  public function testRemoteIndexCheckIsReusedWithinTheTtl(): void {
    $now = time();
    $cache = $this->memoryCache($now);
    $client = $this->countingClient(200, 2);

    foreach ([1, 2, 3] as $call) {
      $payload = json_decode((string) $this->createController(TRUE, 'https://index.example.com', 200, $client, $cache)->handle()->getContent(), TRUE);
      $this->assertTrue($payload['index_exists'], "Call {$call} must report the remote index reachable");
      $now += 20;
    }
  }

  public function testUnreachableRemoteIndexIsCachedToo(): void {
    // The slow case: an unreachable host is when the check runs to its
    // timeout, so it is the one a cache most needs to cover.
    $now = time();
    $cache = $this->memoryCache($now);
    $client = $this->countingClient(503, 1);

    foreach ([1, 2] as $call) {
      $payload = json_decode((string) $this->createController(TRUE, 'https://index.example.com', 503, $client, $cache)->handle()->getContent(), TRUE);
      $this->assertFalse($payload['index_exists'], "Call {$call} must still report the remote index unreachable");
      $this->assertContains('remote_index_unreachable', $payload['status_reasons']);
    }
  }

  public function testRemoteIndexIsCheckedAgainOnceTheTtlPasses(): void {
    $now = time();
    $cache = $this->memoryCache($now);
    $client = $this->countingClient(200, 4);

    $this->createController(TRUE, 'https://index.example.com', 200, $client, $cache)->handle();
    $now += RemoteIndexLocator::DEFAULT_TTL + 1;
    $this->createController(TRUE, 'https://index.example.com', 200, $client, $cache)->handle();
  }

  public function testEachRemoteOriginIsCachedSeparately(): void {
    $now = time();
    $cache = $this->memoryCache($now);
    $client = $this->countingClient(200, 4);

    $this->createController(TRUE, 'https://one.example.com', 200, $client, $cache)->handle();
    $this->createController(TRUE, 'https://two.example.com', 200, $client, $cache)->handle();
  }

  // -------------------------------------------------------------------
  // Remote AI origin: that site's /health is asked, this site's AI config
  // is not judged.
  // -------------------------------------------------------------------

  /**
   * Requests the remote AI origin's /health was asked with.
   *
   * @var \Psr\Http\Message\RequestInterface[]
   */
  private array $aiProbes = [];

  /**
   * A client that answers the AI origin's /health with $health.
   *
   * @param array|\Throwable|int $health
   *   The JSON payload to answer 200 with, an exception to throw, or a bare
   *   status code to answer with no body.
   */
  private function aiOriginClient(array|\Throwable|int $health): ClientInterface {
    $handler = function (RequestInterface $request) use ($health) {
      $this->aiProbes[] = $request;
      if ($health instanceof \Throwable) {
        throw $health;
      }
      $response = is_int($health)
        ? new Response($health)
        : new Response(200, ['Content-Type' => 'application/json'], json_encode($health));
      return \GuzzleHttp\Promise\Create::promiseFor($response);
    };
    return new Client(['handler' => $handler]);
  }

  public function testHealthyRemoteAiOriginDropsThisSitesAiConfigFaults(): void {
    // A provider is chosen here but no key resolves: ai_key_missing, were
    // this site the one answering AI requests. It is not.
    $this->buildIndex(2);
    $this->resolvedKey = new ResolvedApiKey('', ApiKeySource::None, 'anthropic');

    $controller = $this->createController(TRUE, NULL, 200, $this->aiOriginClient(['status' => 'ok']), NULL, 'https://ai.example.com/');
    $payload = json_decode((string) $controller->handle()->getContent(), TRUE);

    $this->assertSame('ok', $payload['status']);
    $this->assertSame([], $payload['status_reasons']);
    $this->assertSame('https://ai.example.com', $payload['ai_origin']);
    $this->assertSame(['status' => 'ok'], $payload['ai_remote']);
    $this->assertSame('https://ai.example.com/api/scolta/v1/health', (string) $this->aiProbes[0]->getUri());
    $this->assertSame('1', $this->aiProbes[0]->getHeaderLine(HealthController::PROBE_HEADER));
  }

  public function testLocalAiOriginKeepsThisSitesAiConfigFaults(): void {
    $this->buildIndex(2);
    $this->resolvedKey = new ResolvedApiKey('', ApiKeySource::None, 'anthropic');

    $payload = json_decode((string) $this->createController(TRUE)->handle()->getContent(), TRUE);

    $this->assertSame('<local>', $payload['ai_origin']);
    $this->assertSame(['ai_key_missing'], $payload['status_reasons']);
    $this->assertArrayNotHasKey('ai_remote', $payload);
  }

  public function testUnreachableRemoteAiOriginDegradesWithItsOwnReason(): void {
    $this->buildIndex(2);
    $client = $this->aiOriginClient(new ConnectException('refused', new Psr7Request('GET', 'https://ai.example.com')));

    $payload = json_decode((string) $this->createController(TRUE, NULL, 200, $client, NULL, 'https://ai.example.com')->handle()->getContent(), TRUE);
    $this->assertSame('degraded', $payload['status']);
    $this->assertSame([HealthController::REASON_REMOTE_AI_UNREACHABLE], $payload['status_reasons']);
    $this->assertSame(['status' => 'unreachable'], $payload['ai_remote']);

    $anonymous = json_decode((string) $this->createController(FALSE, NULL, 200, $client, NULL, 'https://ai.example.com')->handle()->getContent(), TRUE);
    $this->assertSame(['status' => 'degraded'], $anonymous);
  }

  public function testRemoteAiOriginAnsweringWithoutAStatusIsUnreachable(): void {
    // A 200 that is not Scolta's health payload, e.g. a login page.
    $this->buildIndex(2);

    $payload = json_decode((string) $this->createController(TRUE, NULL, 200, $this->aiOriginClient(['ok' => TRUE]), NULL, 'https://ai.example.com')->handle()->getContent(), TRUE);
    $this->assertSame([HealthController::REASON_REMOTE_AI_UNREACHABLE], $payload['status_reasons']);

    $this->aiProbes = [];
    $payload = json_decode((string) $this->createController(TRUE, NULL, 200, $this->aiOriginClient(503), NULL, 'https://ai.example.com')->handle()->getContent(), TRUE);
    $this->assertSame([HealthController::REASON_REMOTE_AI_UNREACHABLE], $payload['status_reasons']);
  }

  public function testDegradedRemoteAiOriginDegradesWithItsOwnReason(): void {
    $this->buildIndex(2);

    $payload = json_decode((string) $this->createController(TRUE, NULL, 200, $this->aiOriginClient(['status' => 'degraded']), NULL, 'https://ai.example.com')->handle()->getContent(), TRUE);
    $this->assertSame('degraded', $payload['status']);
    $this->assertSame([HealthController::REASON_REMOTE_AI_DEGRADED], $payload['status_reasons']);
  }

  public function testRemoteAiOriginAnswerIsReusedWithinTheTtl(): void {
    $this->buildIndex(2);
    $now = time();
    $cache = $this->memoryCache($now);
    $client = $this->aiOriginClient(new ConnectException('refused', new Psr7Request('GET', 'https://ai.example.com')));

    foreach ([1, 2] as $call) {
      $payload = json_decode((string) $this->createController(TRUE, NULL, 200, $client, $cache, 'https://ai.example.com')->handle()->getContent(), TRUE);
      $this->assertSame(['status' => 'unreachable'], $payload['ai_remote'], "Call {$call}");
    }
    $this->assertCount(1, $this->aiProbes, 'A cached answer, a failure included, must not make a second request');

    $now += RemoteIndexLocator::DEFAULT_TTL + 1;
    $this->createController(TRUE, NULL, 200, $client, $cache, 'https://ai.example.com')->handle();
    $this->assertCount(2, $this->aiProbes, 'Once the TTL passes the remote origin is asked again');
  }

  public function testAProbeFromAnotherSiteDoesNotAskThisSitesAiOrigin(): void {
    // Two sites naming each other as AI origin must not ask each other
    // without end.
    $this->buildIndex(2);
    $probe = Request::create('/api/scolta/v1/health', server: ['HTTP_' . str_replace('-', '_', strtoupper(HealthController::PROBE_HEADER)) => '1']);

    $payload = json_decode((string) $this->createController(TRUE, NULL, 200, $this->aiOriginClient(['status' => 'ok']), NULL, 'https://ai.example.com', $probe)->handle()->getContent(), TRUE);

    $this->assertSame([], $this->aiProbes);
    $this->assertSame(['status' => 'not_checked'], $payload['ai_remote']);
    $this->assertSame('ok', $payload['status']);
  }

}
