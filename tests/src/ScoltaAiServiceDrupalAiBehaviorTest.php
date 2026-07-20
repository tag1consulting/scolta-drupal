<?php

declare(strict_types=1);

// Lightweight stubs for the Drupal AI module value objects that
// messageViaDrupalAi()/conversationViaDrupalAi() instantiate directly. The AI
// module is only a `suggest` (not installed in the unit-test environment), so
// these classes are otherwise absent. Guarded with class_exists() so they never
// collide when drupal/ai IS installed (e.g. running the suite inside a site).
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

  /**
   * Behavioral tests for the Drupal AI module dispatch in ScoltaAiService.
   *
   * Unlike ScoltaAiServiceDrupalAiServiceLayerTest (which inspects source
   * text), these tests actually EXECUTE messageViaDrupalAi() and
   * conversationViaDrupalAi() against a fake ai.provider plugin manager, so a
   * regression is caught at runtime rather than by string matching.
   *
   * This is the coverage that was missing when #163 shipped: the source-grep
   * guards asserted a shape (`->chat($input, '', …)`) that was itself the bug,
   * and nothing ever ran the method to discover that
   * getDefaultProviderForOperationType() returns an array
   * (['provider_id' => …, 'model_id' => …]) that must not be passed whole to
   * createInstance(). These tests fail against that buggy code and pass against
   * the fix.
   *
   * The methods only touch $this->aiProviderManager, so the service is created
   * with newInstanceWithoutConstructor() (avoiding the full DI graph) and the
   * protected method is invoked via reflection.
   */
  class ScoltaAiServiceDrupalAiBehaviorTest extends TestCase {

    /**
     * Build a ScoltaAiService with only its aiProviderManager wired up.
     */
    private function serviceWithManager(object $manager): ScoltaAiService {
      $ref = new \ReflectionClass(ScoltaAiService::class);
      $service = $ref->newInstanceWithoutConstructor();
      $prop = $ref->getProperty('aiProviderManager');
      $prop->setAccessible(TRUE);
      $prop->setValue($service, $manager);
      return $service;
    }

    /**
     * Invoke a protected method on the service.
     */
    private function invoke(ScoltaAiService $service, string $method, array $args): mixed {
      $m = new \ReflectionMethod($service, $method);
      $m->setAccessible(TRUE);
      return $m->invokeArgs($service, $args);
    }

    /**
     * A fake ai.provider plugin manager that records how it was called.
     */
    private function fakeManager(array $default): object {
      return new class($default) {
        public array $createInstanceArgs = [];
        public array $chatCalls = [];
        public array $setConfigurationCalls = [];
        public ?string $requestedOperationType = NULL;

        public function __construct(private array $default) {}

        public function getDefaultProviderForOperationType(string $operationType): array {
          $this->requestedOperationType = $operationType;
          return $this->default;
        }

        public function createInstance(mixed $pluginId): object {
          // Record the EXACT argument. The bug passed the whole array here.
          $this->createInstanceArgs[] = $pluginId;
          $recorder = $this;
          return new class($recorder) {
            public function __construct(private object $recorder) {}

            public function setConfiguration(array $configuration): void {
              // max_tokens is applied here, NOT via the chat() $tags argument.
              $this->recorder->setConfigurationCalls[] = $configuration;
            }

            public function chat(object $input, mixed $model, array $tags): object {
              $this->recorder->chatCalls[] = ['model' => $model, 'tags' => $tags];
              return new class {
                public function getNormalized(): object {
                  return new class {
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

    // -----------------------------------------------------------------
    // messageViaDrupalAi()
    // -----------------------------------------------------------------

    public function testMessageUnpacksProviderIdStringToCreateInstance(): void {
      $manager = $this->fakeManager([
        'provider_id' => 'anthropic',
        'model_id' => 'claude-sonnet-4-5-20250929',
      ]);
      $service = $this->serviceWithManager($manager);

      $result = $this->invoke($service, 'messageViaDrupalAi', ['system', 'user', 512]);

      $this->assertSame('GENERATED_TEXT', $result);
      // The core of #163: createInstance() must receive the provider_id STRING,
      // never the array returned by getDefaultProviderForOperationType().
      $this->assertSame(['anthropic'], $manager->createInstanceArgs);
      $this->assertIsString($manager->createInstanceArgs[0]);
    }

    public function testMessagePassesResolvedModelIdToChat(): void {
      $manager = $this->fakeManager([
        'provider_id' => 'anthropic',
        'model_id' => 'claude-sonnet-4-5-20250929',
      ]);
      $service = $this->serviceWithManager($manager);

      $this->invoke($service, 'messageViaDrupalAi', ['system', 'user', 321]);

      $this->assertSame('claude-sonnet-4-5-20250929', $manager->chatCalls[0]['model']);
      // max_tokens is applied via setConfiguration(), not the chat() $tags arg.
      $this->assertSame(['max_tokens' => 321], $manager->setConfigurationCalls[0]);
      $this->assertSame(['scolta'], $manager->chatCalls[0]['tags']);
    }

    public function testMessageRequestsChatOperationType(): void {
      $manager = $this->fakeManager([
        'provider_id' => 'anthropic',
        'model_id' => 'claude-sonnet-4-5-20250929',
      ]);
      $service = $this->serviceWithManager($manager);

      $this->invoke($service, 'messageViaDrupalAi', ['system', 'user', 512]);

      $this->assertSame('chat', $manager->requestedOperationType);
    }

    public function testMessageThrowsWhenNoDefaultProvider(): void {
      $service = $this->serviceWithManager($this->fakeManager([]));

      $this->expectException(\RuntimeException::class);
      $this->invoke($service, 'messageViaDrupalAi', ['system', 'user', 512]);
    }

    public function testMessageThrowsWhenDefaultLacksProviderId(): void {
      // A malformed default (model but no provider_id) must not reach
      // createInstance() with a bad plugin id.
      $service = $this->serviceWithManager($this->fakeManager(['model_id' => 'x']));

      $this->expectException(\RuntimeException::class);
      $this->invoke($service, 'messageViaDrupalAi', ['system', 'user', 512]);
    }

    public function testMessageDefaultsModelToEmptyStringWhenAbsent(): void {
      // provider_id present but no model_id → chat() gets '' (provider default).
      $manager = $this->fakeManager(['provider_id' => 'openai']);
      $service = $this->serviceWithManager($manager);

      $this->invoke($service, 'messageViaDrupalAi', ['system', 'user', 100]);

      $this->assertSame(['openai'], $manager->createInstanceArgs);
      $this->assertSame('', $manager->chatCalls[0]['model']);
    }

    // -----------------------------------------------------------------
    // conversationViaDrupalAi()
    // -----------------------------------------------------------------

    public function testConversationUnpacksProviderIdStringToCreateInstance(): void {
      $manager = $this->fakeManager([
        'provider_id' => 'anthropic',
        'model_id' => 'claude-sonnet-4-5-20250929',
      ]);
      $service = $this->serviceWithManager($manager);

      $messages = [['role' => 'user', 'content' => 'hi']];
      $result = $this->invoke($service, 'conversationViaDrupalAi', ['system', $messages, 321]);

      $this->assertSame('GENERATED_TEXT', $result);
      $this->assertSame(['anthropic'], $manager->createInstanceArgs);
      $this->assertSame('claude-sonnet-4-5-20250929', $manager->chatCalls[0]['model']);
      $this->assertSame('chat', $manager->requestedOperationType);
      // max_tokens is applied via setConfiguration(), not the chat() $tags arg.
      $this->assertSame(['max_tokens' => 321], $manager->setConfigurationCalls[0]);
      $this->assertSame(['scolta'], $manager->chatCalls[0]['tags']);
    }

    public function testConversationDefaultsModelToEmptyStringWhenAbsent(): void {
      // Mirrors testMessageDefaultsModelToEmptyStringWhenAbsent for the
      // conversation path: provider_id present but no model_id → chat() gets ''.
      $manager = $this->fakeManager(['provider_id' => 'openai']);
      $service = $this->serviceWithManager($manager);

      $messages = [['role' => 'user', 'content' => 'hi']];
      $this->invoke($service, 'conversationViaDrupalAi', ['system', $messages, 100]);

      $this->assertSame(['openai'], $manager->createInstanceArgs);
      $this->assertSame('', $manager->chatCalls[0]['model']);
    }

    public function testConversationThrowsWhenNoDefaultProvider(): void {
      $service = $this->serviceWithManager($this->fakeManager([]));

      $this->expectException(\RuntimeException::class);
      $this->invoke($service, 'conversationViaDrupalAi', ['system', [], 512]);
    }

    public function testConversationThrowsWhenDefaultLacksProviderId(): void {
      // Mirrors testMessageThrowsWhenDefaultLacksProviderId for the conversation
      // path: a malformed default (model but no provider_id) must not reach
      // createInstance().
      $service = $this->serviceWithManager($this->fakeManager(['model_id' => 'x']));

      $this->expectException(\RuntimeException::class);
      $this->invoke($service, 'conversationViaDrupalAi', ['system', [], 512]);
    }

  }

}
