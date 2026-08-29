<?php

declare(strict_types=1);

// Lightweight stubs for the Drupal AI module value objects that
// messageViaDrupalAi()/conversationViaDrupalAi() instantiate directly. The AI
// module is only a `suggest` (not installed in the unit-test environment), so
// these classes are otherwise absent. Guarded with class_exists() so they never
// collide when drupal/ai IS installed (e.g. running the suite inside a site)
// or when ScoltaAiServiceDrupalAiBehaviorTest already defined the same stubs.
namespace Drupal\ai\OperationType\Chat {

  if (!class_exists(ChatMessage::class)) {

    /**
     * Test stub for the AI module's ChatMessage value object.
     */
    class ChatMessage {

      public function __construct(public string $role, public string $message) {}

    }

  }

  if (!class_exists(ChatInput::class)) {

    /**
     * Test stub for the AI module's ChatInput value object.
     */
    class ChatInput {

      public function __construct(public array $messages = []) {}

    }

  }

}

namespace Drupal\scolta\Tests {

  use Drupal\scolta\Service\ScoltaAiService;
  use PHPUnit\Framework\TestCase;
  use Psr\Log\AbstractLogger;
  use Tag1\Scolta\Config\ScoltaConfig;
  use Tag1\Scolta\Service\AiServiceAdapter;

  /**
   * Behavioral tests for the Drupal AI opt-in gate in ScoltaAiService.
   *
   * tryFrameworkAi() and tryFrameworkConversation() must only activate when
   * ai_provider is explicitly set to 'drupal_ai', NOT merely when the Drupal
   * AI module is installed. This guards against the bug where installing
   * drupal/ai silently rerouted Amazee.ai requests through the Drupal AI
   * module's own provider/key config.
   *
   * These tests EXECUTE the protected methods (via reflection, the same
   * harness as ScoltaAiServiceDrupalAiBehaviorTest) against a fake ai.provider
   * plugin manager and a spy logger, so a regression is caught at runtime.
   * The inner Drupal-AI dispatch itself is covered by
   * ScoltaAiServiceDrupalAiBehaviorTest; here only the gate is under test.
   */
  class ScoltaAiServiceDrupalAiOptInTest extends TestCase {

    /**
     * Build a ScoltaAiService without its full DI graph.
     *
     * Only the properties the opt-in gate touches are wired: the parent's
     * config (which carries ai_provider), the logger, and the optional
     * ai.provider plugin manager.
     */
    private function service(string $provider, ?object $manager, object $logger): ScoltaAiService {
      $ref = new \ReflectionClass(ScoltaAiService::class);
      $service = $ref->newInstanceWithoutConstructor();

      $configProp = new \ReflectionProperty(AiServiceAdapter::class, 'config');
      $configProp->setValue($service, ScoltaConfig::fromArray(['ai_provider' => $provider]));

      $loggerProp = $ref->getProperty('logger');
      $loggerProp->setValue($service, $logger);

      $managerProp = $ref->getProperty('aiProviderManager');
      $managerProp->setValue($service, $manager);

      return $service;
    }

    /**
     * Invoke a protected method on the service.
     */
    private function invoke(ScoltaAiService $service, string $method, array $args): mixed {
      $m = new \ReflectionMethod($service, $method);
      return $m->invokeArgs($service, $args);
    }

    /**
     * A spy logger recording every record it receives.
     */
    private function spyLogger(): object {
      return new class() extends AbstractLogger {
        public array $records = [];

        public function log($level, string|\Stringable $message, array $context = []): void {
          $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
          ];
        }

      };
    }

    /**
     * A fake ai.provider manager that records whether it was touched at all.
     */
    private function recordingManager(): object {
      return new class() {
        public array $calls = [];

        public function getDefaultProviderForOperationType(string $operationType): array {
          $this->calls[] = 'getDefaultProviderForOperationType';
          return ['provider_id' => 'anthropic', 'model_id' => 'claude-x'];
        }

        public function createInstance(mixed $pluginId): object {
          $this->calls[] = 'createInstance';
          return new class() {

            public function setConfiguration(array $configuration): void {}

            public function chat(object $input, mixed $model, array $tags): object {
              return new class() {

                public function getNormalized(): object {
                  return new class() {

                    public function getText(): string {
                      return 'GENERATED_TEXT';
                    }

                  };
                }

              };
            }

          };
        }

      };
    }

    /**
     * A fake manager whose first call throws.
     */
    private function throwingManager(): object {
      return new class() {

        public function getDefaultProviderForOperationType(string $operationType): array {
          throw new \RuntimeException('provider blew up');
        }

      };
    }

    // -----------------------------------------------------------------
    // Provider is NOT 'drupal_ai' — the gate returns NULL untouched.
    // -----------------------------------------------------------------

    public function testTryFrameworkAiReturnsNullForOtherProviders(): void {
      // Installing the AI module (manager present) must not change routing:
      // only the explicit 'drupal_ai' selection opts in.
      foreach (['', 'amazee', 'anthropic', 'openai'] as $provider) {
        $manager = $this->recordingManager();
        $logger = $this->spyLogger();
        $service = $this->service($provider, $manager, $logger);

        $result = $this->invoke($service, 'tryFrameworkAi', ['system', 'user', 512]);

        $this->assertNull($result, "Provider '$provider' must not route through Drupal AI");
        $this->assertSame([], $manager->calls, "Provider '$provider' must never touch the plugin manager");
        $this->assertSame([], $logger->records, "Provider '$provider' must not log");
      }
    }

    public function testTryFrameworkConversationReturnsNullForOtherProviders(): void {
      foreach (['', 'amazee', 'anthropic', 'openai'] as $provider) {
        $manager = $this->recordingManager();
        $logger = $this->spyLogger();
        $service = $this->service($provider, $manager, $logger);

        $result = $this->invoke($service, 'tryFrameworkConversation', ['system', [['role' => 'user', 'content' => 'hi']], 512]);

        $this->assertNull($result, "Provider '$provider' must not route through Drupal AI");
        $this->assertSame([], $manager->calls, "Provider '$provider' must never touch the plugin manager");
        $this->assertSame([], $logger->records, "Provider '$provider' must not log");
      }
    }

    // -----------------------------------------------------------------
    // 'drupal_ai' selected but the module is missing — NULL + warning.
    // -----------------------------------------------------------------

    public function testTryFrameworkAiWarnsWhenModuleMissing(): void {
      $logger = $this->spyLogger();
      $service = $this->service('drupal_ai', NULL, $logger);

      $result = $this->invoke($service, 'tryFrameworkAi', ['system', 'user', 512]);

      $this->assertNull($result);
      $this->assertCount(1, $logger->records);
      $this->assertSame('warning', $logger->records[0]['level']);
      $this->assertStringContainsString('not installed', $logger->records[0]['message']);
    }

    public function testTryFrameworkConversationWarnsWhenModuleMissing(): void {
      $logger = $this->spyLogger();
      $service = $this->service('drupal_ai', NULL, $logger);

      $result = $this->invoke($service, 'tryFrameworkConversation', ['system', [], 512]);

      $this->assertNull($result);
      $this->assertCount(1, $logger->records);
      $this->assertSame('warning', $logger->records[0]['level']);
      $this->assertStringContainsString('not installed', $logger->records[0]['message']);
    }

    // -----------------------------------------------------------------
    // 'drupal_ai' selected and the manager present — delegates.
    // -----------------------------------------------------------------

    public function testTryFrameworkAiDelegatesWhenOptedIn(): void {
      $manager = $this->recordingManager();
      $logger = $this->spyLogger();
      $service = $this->service('drupal_ai', $manager, $logger);

      $result = $this->invoke($service, 'tryFrameworkAi', ['system', 'user', 512]);

      $this->assertSame('GENERATED_TEXT', $result);
      $this->assertContains('createInstance', $manager->calls);
      $this->assertSame([], $logger->records);
    }

    public function testTryFrameworkConversationDelegatesWhenOptedIn(): void {
      $manager = $this->recordingManager();
      $logger = $this->spyLogger();
      $service = $this->service('drupal_ai', $manager, $logger);

      $result = $this->invoke($service, 'tryFrameworkConversation', ['system', [['role' => 'user', 'content' => 'hi']], 512]);

      $this->assertSame('GENERATED_TEXT', $result);
      $this->assertContains('createInstance', $manager->calls);
      $this->assertSame([], $logger->records);
    }

    // -----------------------------------------------------------------
    // The manager throws — NULL + warning, never a propagated exception.
    // -----------------------------------------------------------------

    public function testTryFrameworkAiFallsBackWithWarningWhenManagerThrows(): void {
      $logger = $this->spyLogger();
      $service = $this->service('drupal_ai', $this->throwingManager(), $logger);

      $result = $this->invoke($service, 'tryFrameworkAi', ['system', 'user', 512]);

      $this->assertNull($result, 'A Drupal AI failure must fall back to the built-in client, not propagate');
      $this->assertCount(1, $logger->records);
      $this->assertSame('warning', $logger->records[0]['level']);
      $this->assertSame('provider blew up', $logger->records[0]['context']['@msg']);
    }

    public function testTryFrameworkConversationFallsBackWithWarningWhenManagerThrows(): void {
      $logger = $this->spyLogger();
      $service = $this->service('drupal_ai', $this->throwingManager(), $logger);

      $result = $this->invoke($service, 'tryFrameworkConversation', ['system', [], 512]);

      $this->assertNull($result, 'A Drupal AI failure must fall back to the built-in client, not propagate');
      $this->assertCount(1, $logger->records);
      $this->assertSame('warning', $logger->records[0]['level']);
      $this->assertSame('provider blew up', $logger->records[0]['context']['@msg']);
    }

    // -----------------------------------------------------------------
    // hasDrupalAiModule()
    // -----------------------------------------------------------------

    public function testHasDrupalAiModuleIsFalseWithoutTheManager(): void {
      $service = $this->service('drupal_ai', NULL, $this->spyLogger());

      $this->assertFalse($service->hasDrupalAiModule());
    }

    public function testHasDrupalAiModuleIsTrueWithTheManager(): void {
      $service = $this->service('drupal_ai', $this->recordingManager(), $this->spyLogger());

      $this->assertTrue($service->hasDrupalAiModule());
    }

  }

}
