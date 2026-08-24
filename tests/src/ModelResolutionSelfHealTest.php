<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\scolta_ui\Service\ScoltaAiService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\Exception\ApiKeyMissingException;

/**
 * Coverage for the Amazee model-resolution self-heal adoption.
 *
 * The Amazee provisioner persists credentials (litellm token + url) and the
 * resolved model names in two steps. If the /model/info step fails, credentials
 * are stored with no resolved model: AutoProvisioner::ensureAiAvailable() then
 * no-opped forever on the stored creds, and createClient() fell back to the
 * shipped dated default (claude-sonnet-4-5-20250929), which the Amazee LiteLLM
 * gateway rejects with HTTP 400 — so summarize silently returned nothing and
 * expand ran unexpanded, permanently.
 *
 * ScoltaAiService now (a) passes a hasResolvedModels predicate so the library
 * re-resolves against the stored key when only the dated default is present,
 * and (b) degrades to a key-less client (HTTP 200, unexpanded/no-summary)
 * rather than sending the gateway the dated default.
 *
 * The unit-test vendor lacks drupal/core, so ScoltaAiService cannot be
 * instantiated here (the same reason the other AI-service tests are
 * source-level). These tests prove the Drupal-specific contract three ways:
 * the predicate's logic directly (it loads as a static), the library self-heal
 * driven by that exact predicate, and the key-less degrade behavior — plus a
 * structural check that createClient() carries the wiring.
 */
class ModelResolutionSelfHealTest extends TestCase {

  private const TRIAL_RESPONSE = '{"key": {"litellm_token": "sk-stored-token", "litellm_api_url": "https://llm.test.amazee.ai", "region": "test-region"}}';
  private const MODEL_INFO_RESPONSE = '{"data": [{"model_name": "claude-sonnet-4-5"}, {"model_name": "claude-haiku-4-5"}]}';

  /**
   * The dated default shipped in config — what the gateway rejects with 400.
   */
  private const DATED_DEFAULT = 'claude-sonnet-4-5-20250929';

  // -------------------------------------------------------------------------
  // The predicate: it must report FALSE for the dated default (the no-op trap).
  // -------------------------------------------------------------------------

  /**
   * @dataProvider unresolvedModels
   */
  public function testPredicateReportsUnresolved(?string $aiModel): void {
    $this->assertFalse(
      $this->invokePredicate($aiModel),
      'modelIsResolved() must report FALSE for a NULL/empty/dated-default model, '
      . 'or the self-heal would never fire and the bug would ship.'
    );
  }

  public static function unresolvedModels(): array {
    return [
      'null' => [NULL],
      'empty' => [''],
      'shipped dated default' => [self::DATED_DEFAULT],
    ];
  }

  public function testPredicateReportsResolvedForRealModelName(): void {
    $this->assertTrue(
      $this->invokePredicate('claude-4-5-sonnet'),
      'A genuinely resolved model name (what onModelsResolved persists) is resolved.'
    );
    // The dated-default literal in the predicate must equal AiClient's default.
    $this->assertSame(self::DATED_DEFAULT, AiClient::DEFAULT_MODEL);
  }

  // -------------------------------------------------------------------------
  // Self-heal: the real AutoProvisioner, driven by the actual predicate.
  // -------------------------------------------------------------------------

  public function testStoredCredsWithOnlyDatedDefaultSelfHeal(): void {
    // The half-provisioned state: credentials stored, but the gateway-scoped
    // key still carries the shipped dated default a migrated site can hold
    // (model resolution never succeeded).
    $storage = new InMemorySelfHealStorage([
      'litellm_token' => 'sk-stored-token',
      'litellm_api_url' => 'https://llm.test.amazee.ai',
      'region' => 'test-region',
    ]);
    $config = ['amazee_model' => self::DATED_DEFAULT];

    // Only /model/info is queued — provisioning a NEW trial would throw (queue
    // has no generate-trial-access response), proving the heal re-resolves
    // against the stored key without burning a fresh trial.
    $client = $this->makeAmazeeClient([
      new Response(200, [], self::MODEL_INFO_RESPONSE),
    ]);

    $provisioned = AutoProvisioner::ensureAiAvailable(
      $storage,
      hasExplicitApiKey: FALSE,
      onModelsResolved: function (string $aiModel, string $aiExpansionModel) use (&$config): void {
        if ($aiModel !== '') {
          $config['amazee_model'] = $aiModel;
        }
      },
      client: $client,
      // The EXACT predicate ScoltaAiService wires.
      hasResolvedModels: fn (): bool => $this->invokePredicate($config['amazee_model']),
    );

    $this->assertFalse($provisioned, 'A model-only heal is not a fresh-trial provision');
    $this->assertSame(
      'claude-sonnet-4-5',
      $config['amazee_model'],
      'The stored dated default must be healed to a genuinely resolved model.'
    );
  }

  public function testNaiveNonEmptyPredicateWouldNotHeal(): void {
    // Documents the trap: a "model is non-empty" predicate reports TRUE in the
    // dated-default state, so AutoProvisioner no-ops and the bug ships. This is
    // why testPredicateReportsUnresolved() asserts FALSE for the dated default.
    $storage = new InMemorySelfHealStorage([
      'litellm_token' => 'sk-stored-token',
      'litellm_api_url' => 'https://llm.test.amazee.ai',
      'region' => 'test-region',
    ]);
    $config = ['amazee_model' => self::DATED_DEFAULT];

    // No responses queued: any Amazee call would throw. The naive predicate
    // returning TRUE must keep ensureAiAvailable a no-op, so nothing is called.
    $client = $this->makeAmazeeClient([]);

    AutoProvisioner::ensureAiAvailable(
      $storage,
      hasExplicitApiKey: FALSE,
      onModelsResolved: function (string $aiModel) use (&$config): void {
        $config['amazee_model'] = $aiModel;
      },
      client: $client,
      hasResolvedModels: fn (): bool => !empty($config['amazee_model']),
    );

    $this->assertSame(
      self::DATED_DEFAULT,
      $config['amazee_model'],
      'A naive non-empty predicate leaves the dated default in place (the bug).'
    );
  }

  // -------------------------------------------------------------------------
  // Degrade: a key-less client throws ApiKeyMissingException and never sends
  // the dated default to the gateway.
  // -------------------------------------------------------------------------

  public function testDegradedClientThrowsAndNeverCallsGateway(): void {
    // createDegradedClient() strips the api key from the Amazee (openai) config.
    // The first call must throw ApiKeyMissingException — the HTTP 200 degrade
    // signal the controllers act on — without ever reaching the gateway.
    $mock = new MockHandler([
      // A 400, exactly as the gateway answers the dated default. If the degraded
      // client made any HTTP call it would consume this and we'd see a different
      // failure; ApiKeyMissingException proves it short-circuited first.
      new Response(400, [], '{"error": {"message": "Invalid model name"}}'),
    ]);
    $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

    $degraded = new AiClient([
      'provider' => 'openai',
      'api_key' => '',
      'base_url' => 'https://llm.test.amazee.ai',
      'model' => self::DATED_DEFAULT,
    ], $httpClient);

    $this->expectException(ApiKeyMissingException::class);
    try {
      $degraded->message('system', 'user');
    }
    finally {
      $this->assertSame(1, $mock->count(), 'The degraded client must make NO HTTP call');
    }
  }

  // -------------------------------------------------------------------------
  // Structural: createClient() carries the wiring with the dated-default gate.
  // -------------------------------------------------------------------------

  public function testCreateClientPassesResolvedModelsPredicate(): void {
    $src = $this->serviceSource();
    $this->assertStringContainsString('hasResolvedModels:', $src,
      'createClient() must pass the hasResolvedModels predicate to ensureAiAvailable()');
    $this->assertStringContainsString('self::modelIsResolved(', $src,
      'The predicate must delegate to modelIsResolved()');
  }

  /**
   * Both readers must consult the gateway-scoped key the callback writes.
   *
   * scolta-drupal#187: they used to read ai_model, which after the fix holds
   * the operator's provider-native choice. Left there, the predicate would
   * report TRUE on any site whose administrator had named a model, and the
   * self-heal would never fire on precisely the sites needing it.
   */
  public function testResolvedModelReadersUseTheGatewayScopedKey(): void {
    $src = $this->serviceSource();

    $found = preg_match_all(
      "/self::modelIsResolved\(\s*\\\$this->configFactory->get\('scolta\.settings'\)->get\('([a-z_]+)'\)/",
      $src,
      $matches
    );

    // Two callers read config: the gate that decides whether a heal is
    // warranted at all, and the hasResolvedModels predicate. The degrade
    // guard reads what the heal returned instead.
    $this->assertSame(2, $found, 'Every modelIsResolved() call site that reads config must read the same key');
    $this->assertSame(
      ['amazee_model', 'amazee_model'],
      $matches[1],
      'Every modelIsResolved() caller must read amazee_model, not the operator-facing ai_model'
    );
  }

  public function testPredicateExcludesTheDatedDefault(): void {
    $src = $this->serviceSource();
    $this->assertStringContainsString('AiClient::DEFAULT_MODEL', $src,
      'modelIsResolved() must exclude the shipped dated default (AiClient::DEFAULT_MODEL)');
  }

  public function testCreateClientDegradesWhenUnresolved(): void {
    $src = $this->serviceSource();
    $this->assertStringContainsString('createDegradedClient()', $src,
      'createClient() must degrade (key-less client) when the model is unresolved');
    $this->assertMatchesRegularExpression(
      '/\$resolved->isAmazee\(\) && \$resolved->amazeeCredentialsStored\s*\n?\s*&& \$unresolvedModel/',
      $src,
      'The heal and its degrade must be gated on the selected provider, a stored connection, and an unresolved model'
    );
  }

  /**
   * The model heal must never run where there is nothing stored to heal.
   *
   * The gate is the whole of the opt-in guarantee on the request path: the
   * library makes no outbound call for a site with no stored connection, and
   * this keeps the adapter from asking it to in the first place. Previously
   * this call site fired whenever the key source was 'none', so an ordinary
   * page load on an unconfigured site reached the gateway.
   */
  public function testCreateClientOnlyHealsAnExistingConnection(): void {
    $src = $this->serviceSource();
    $start = strpos($src, 'protected function createClient(): AiClient {');
    $this->assertNotFalse($start, 'ScoltaAiService must override createClient()');
    $end = strpos($src, "\n  }", $start);
    $body = substr($src, $start, $end - $start);

    $this->assertStringContainsString('$resolved->amazeeCredentialsStored', $body,
      'The gateway call must require a connection that is already stored');
    $this->assertStringNotContainsString('!$resolved->amazeeCredentialsStored', $body,
      'createClient() must never call the gateway on the strength of nothing being stored');
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  /**
   * Invoke the protected static predicate without a Drupal bootstrap.
   */
  private function invokePredicate(?string $aiModel): bool {
    // ReflectionMethod ignores visibility since PHP 8.1 (the package floor).
    $method = new \ReflectionMethod(ScoltaAiService::class, 'modelIsResolved');
    return $method->invoke(NULL, $aiModel);
  }

  private function serviceSource(): string {
    return file_get_contents(dirname(__DIR__, 2) . '/src/Service/ScoltaAiService.php');
  }

  /**
   * Build an AmazeeClient backed by a MockHandler queue.
   */
  private function makeAmazeeClient(array $responses): AmazeeClient {
    return new AmazeeClient(
      'https://api.amazee.ai',
      new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
    );
  }

}

/**
 * Minimal in-memory ConfigStorageInterface for the self-heal store.
 */
class InMemorySelfHealStorage implements ConfigStorageInterface {

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
