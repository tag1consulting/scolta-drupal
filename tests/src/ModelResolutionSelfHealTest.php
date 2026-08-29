<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\scolta\Service\ScoltaAiService;
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
 * driven by that exact predicate, and the key-less degrade behavior.
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
