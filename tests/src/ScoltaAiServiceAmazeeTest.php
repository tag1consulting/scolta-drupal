<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\AiProvider\Amazee\BudgetExceededHandler;
use Drupal\scolta\Service\ScoltaAiService;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

/**
 * Verifies the Amazee.ai integration in ScoltaAiService without a bootstrap.
 *
 * Behavioral where the code can run without a Drupal container (the budget
 * hook, buildConfig() credential resolution), reflection for the constructor
 * contract, and parsed-YAML assertions for the service wiring.
 */
class ScoltaAiServiceAmazeeTest extends TestCase {

  protected function setUp(): void {
    // settingsApiKey() reads Drupal Settings; prime the singleton empty so
    // no settings.php key competes with what each test configures.
    new Settings([]);
    putenv('SCOLTA_API_KEY');
  }

  protected function tearDown(): void {
    putenv('SCOLTA_API_KEY');
  }

  // -------------------------------------------------------------------
  // handlePossibleBudgetException() — the budget hook (behavioral).
  // -------------------------------------------------------------------

  public function testBudgetHookIgnoresOrdinaryRuntimeExceptions(): void {
    $state = $this->spyState();
    $service = $this->serviceWithBudgetHandler(new BudgetExceededHandler($this->createMock(MessengerInterface::class), $state));

    $this->invoke($service, 'handlePossibleBudgetException', [new \RuntimeException('HTTP 500 from the provider')]);

    // No exception thrown, and the handler was never notified.
    $this->assertSame([], $state->getCalls, 'The handler must not be notified for a non-budget error');
  }

  public function testBudgetHookConvertsBudgetErrorAndNotifiesHandler(): void {
    // A real BudgetExceededHandler over a spy State: get() reports a recent
    // notice so handle() takes its throttled early return — which still proves
    // the hook notified it — without needing the container that t()/Url want.
    $state = $this->spyState(time());
    $service = $this->serviceWithBudgetHandler(new BudgetExceededHandler($this->createMock(MessengerInterface::class), $state));

    try {
      $this->invoke($service, 'handlePossibleBudgetException', [
        new \RuntimeException('Error: Budget has been exceeded! Current cost: 5.0'),
      ]);
      $this->fail('A budget error must be re-thrown as AmazeeBudgetExceededException');
    }
    catch (AmazeeBudgetExceededException $e) {
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious(),
        'The original exception must be chained');
    }

    $this->assertContains('scolta.amazee.budget_notice_time', $state->getCalls,
      'handle() must have been invoked (it reads its throttle state)');
  }

  public function testBudgetHookThrowsEvenWithoutAHandler(): void {
    // The handler is optional; the conversion must not depend on it.
    $service = $this->serviceWithBudgetHandler(NULL);

    $this->expectException(AmazeeBudgetExceededException::class);
    $this->invoke($service, 'handlePossibleBudgetException', [
      new \RuntimeException('Budget has been exceeded!'),
    ]);
  }

  // -------------------------------------------------------------------
  // buildConfig() — Amazee credential resolution (behavioral).
  // -------------------------------------------------------------------

  public function testBuildConfigInjectsStoredAmazeeCredentials(): void {
    $service = $this->serviceForBuildConfig(
      settings: [
        'ai_provider' => 'amazee',
        'amazee_model' => 'claude-4-5-sonnet',
        'amazee_expansion_model' => 'claude-haiku-4-5',
      ],
      storage: $this->storageWith('sk-stored-token', 'https://llm.test.amazee.ai'),
    );

    $config = $this->invoke($service, 'buildConfig', []);

    // The decrypted token becomes the key, the gateway becomes the provider,
    // and the gateway-scoped model aliases replace the operator-facing ones.
    $this->assertSame('sk-stored-token', $config->aiApiKey);
    $this->assertSame('openai', $config->aiProvider, 'Amazee routes through the OpenAI-compatible LiteLLM gateway');
    $this->assertSame('https://llm.test.amazee.ai', $config->aiBaseUrl);
    $this->assertSame('claude-4-5-sonnet', $config->aiModel);
    $this->assertSame('claude-haiku-4-5', $config->aiExpansionModel);
  }

  public function testBuildConfigPrefersAnExplicitKeyOverAmazeeCredentials(): void {
    putenv('SCOLTA_API_KEY=sk-explicit-env-key');

    $service = $this->serviceForBuildConfig(
      settings: [
        'ai_provider' => 'anthropic',
        'ai_model' => 'claude-native-model',
      ],
      storage: $this->storageWith('sk-stored-token', 'https://llm.test.amazee.ai'),
    );

    $config = $this->invoke($service, 'buildConfig', []);

    // Stored credentials lose to the explicit key: nothing Amazee leaks in.
    $this->assertSame('sk-explicit-env-key', $config->aiApiKey);
    $this->assertSame('anthropic', $config->aiProvider);
    $this->assertSame('', $config->aiBaseUrl, 'The gateway base URL must not be injected');
    $this->assertSame('claude-native-model', $config->aiModel, 'The operator-facing model must survive');
  }

  public function testBuildConfigIgnoresStoredCredentialsWhenAmazeeNotSelected(): void {
    // Credentials stored but the operator selected another provider: the
    // managed gateway must stay out of AI traffic entirely.
    $service = $this->serviceForBuildConfig(
      settings: ['ai_provider' => 'anthropic'],
      storage: $this->storageWith('sk-stored-token', 'https://llm.test.amazee.ai'),
    );

    $config = $this->invoke($service, 'buildConfig', []);

    $this->assertSame('', $config->aiApiKey, 'The stored token must not become the key');
    $this->assertSame('anthropic', $config->aiProvider);
    $this->assertSame('', $config->aiBaseUrl);
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  /**
   * Invoke a protected method on the service.
   */
  private function invoke(ScoltaAiService $service, string $method, array $args): mixed {
    $m = new \ReflectionMethod($service, $method);
    return $m->invokeArgs($service, $args);
  }

  /**
   * Build a service instance with only the budget handler wired.
   */
  private function serviceWithBudgetHandler(?BudgetExceededHandler $handler): ScoltaAiService {
    $ref = new \ReflectionClass(ScoltaAiService::class);
    $service = $ref->newInstanceWithoutConstructor();
    $prop = $ref->getProperty('budgetHandler');
    $prop->setValue($service, $handler);
    return $service;
  }

  /**
   * Build a service instance ready to run buildConfig().
   */
  private function serviceForBuildConfig(array $settings, ?ConfigStorageInterface $storage): ScoltaAiService {
    $configs = [
      'scolta.settings' => $this->fakeConfig($settings),
      'system.site' => $this->fakeConfig(['name' => 'Test Site']),
    ];
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(
      static fn (string $name): object => $configs[$name],
    );

    $ref = new \ReflectionClass(ScoltaAiService::class);
    $service = $ref->newInstanceWithoutConstructor();
    foreach ([
      'configFactory' => $configFactory,
      'httpClient' => $this->createMock(ClientInterface::class),
      'logger' => $this->nullLogger(),
      'amazeeConfigStorage' => $storage,
    ] as $name => $value) {
      $prop = $ref->getProperty($name);
      $prop->setValue($service, $value);
    }
    return $service;
  }

  /**
   * A read-only config object exposing get() the way ImmutableConfig does.
   */
  private function fakeConfig(array $values): object {
    return new class($values) {

      public function __construct(private array $values) {}

      public function get(string $key = ''): mixed {
        return $key === '' ? $this->values : ($this->values[$key] ?? NULL);
      }

    };
  }

  /**
   * A credential store holding a decrypted token.
   */
  private function storageWith(string $token, string $url): ConfigStorageInterface {
    return new class($token, $url) implements ConfigStorageInterface {

      public function __construct(private string $token, private string $url) {}

      public function store(string $litellmToken, string $litellmApiUrl, string $region): void {}

      public function load(): ?array {
        return [
          'litellm_token' => $this->token,
          'litellm_api_url' => $this->url,
          'region' => 'test-region',
        ];
      }

      public function clear(): void {}

    };
  }

  /**
   * A StateInterface spy recording get() calls.
   */
  private function spyState(mixed $getReturn = NULL): StateInterface {
    return new class($getReturn) implements StateInterface {
      public array $getCalls = [];

      public function __construct(private mixed $getReturn) {}

      public function get($key, $default = NULL) {
        $this->getCalls[] = $key;
        return $this->getReturn ?? $default;
      }

      public function getMultiple(array $keys) {
        return [];
      }

      public function set($key, $value) {}

      public function setMultiple(array $data) {}

      public function delete($key) {}

      public function deleteMultiple(array $keys) {}

      public function resetCache() {}

      public function getValuesSetDuringRequest(string $key): ?array {
        return NULL;
      }

    };
  }

  /**
   * A logger that discards everything.
   */
  private function nullLogger(): AbstractLogger {
    return new class() extends AbstractLogger {

      public function log($level, string|\Stringable $message, array $context = []): void {}

    };
  }

}
