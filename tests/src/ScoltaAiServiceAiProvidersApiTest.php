<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\scolta\Service\ScoltaAiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Tests routing through the AI Providers API module in ScoltaAiService.
 */
class ScoltaAiServiceAiProvidersApiTest extends TestCase {

  public function testOtherProvidersLeaveTheModuleAlone(): void {
    $aiProvidersApi = $this->fakeAiProvidersApi();
    $service = $this->service('anthropic', $aiProvidersApi, $this->spyLogger());

    $this->assertNull($this->invoke($service, 'tryFrameworkAi', ['system', 'user', 512]));
    $messages = [['role' => 'user', 'content' => 'user']];
    $this->assertNull($this->invoke($service, 'tryFrameworkConversation', ['system', $messages, 512]));
    $this->assertSame([], $aiProvidersApi->calls);
  }

  public function testMessageSendsSystemPromptAndTokenLimit(): void {
    $aiProvidersApi = $this->fakeAiProvidersApi();
    $service = $this->service('ai_providers_api', $aiProvidersApi, $this->spyLogger());

    $this->assertSame('GENERATED_TEXT', $this->invoke($service, 'tryFrameworkAi', ['system', 'user', 512]));
    $this->assertSame([
      ['claude', 'user', ['system' => 'system', 'max_tokens' => 512]],
    ], $aiProvidersApi->calls);
  }

  public function testConversationSendsEarlierTurnsAsHistory(): void {
    $aiProvidersApi = $this->fakeAiProvidersApi();
    $service = $this->service('ai_providers_api', $aiProvidersApi, $this->spyLogger());

    $messages = [
      ['role' => 'user', 'content' => 'first question'],
      ['role' => 'assistant', 'content' => 'first answer'],
      ['role' => 'user', 'content' => 'follow-up'],
    ];
    $result = $this->invoke($service, 'tryFrameworkConversation', ['system', $messages, 321]);

    $this->assertSame('GENERATED_TEXT', $result);
    $options = [
      'system' => 'system',
      'history' => array_slice($messages, 0, 2),
      'max_tokens' => 321,
    ];
    $this->assertSame([['claude', 'follow-up', $options]], $aiProvidersApi->calls);
  }

  public function testMissingModuleTurnsAiOff(): void {
    $logger = $this->spyLogger();
    $service = $this->service('ai_providers_api', NULL, $logger);

    try {
      $this->invoke($service, 'tryFrameworkAi', ['system', 'user', 512]);
      $this->fail('ApiKeyMissingException expected.');
    }
    catch (ApiKeyMissingException) {
    }
    $this->assertSame('warning', $logger->records[0]['level']);
  }

  public function testDisabledProviderTurnsAiOff(): void {
    $aiProvidersApi = $this->fakeAiProvidersApi(['ollama' => 'Ollama']);
    $service = $this->service('ai_providers_api', $aiProvidersApi, $this->spyLogger());

    $this->expectException(ApiKeyMissingException::class);
    try {
      $this->invoke($service, 'tryFrameworkConversation', ['system', [['role' => 'user', 'content' => 'user']], 512]);
    }
    finally {
      $this->assertSame([], $aiProvidersApi->calls);
    }
  }

  public function testProviderErrorPropagates(): void {
    $service = $this->service('ai_providers_api', $this->fakeAiProvidersApi(throw: new \RuntimeException('provider down')), $this->spyLogger());

    $this->expectExceptionMessage('provider down');
    $this->invoke($service, 'tryFrameworkAi', ['system', 'user', 512]);
  }

  public function testExpansionUsesExpansionProvider(): void {
    $aiProvidersApi = $this->fakeAiProvidersApi(['claude' => 'Claude', 'haiku' => 'Haiku']);
    $service = $this->service('ai_providers_api', $aiProvidersApi, $this->spyLogger(), 'haiku');

    $service->messageForOperation('expand_query', 'system', 'query', 512);
    $service->messageForOperation('summarize', 'system', 'results', 512);
    $service->message('system', 'user', 512);

    $this->assertSame(['haiku', 'claude', 'claude'], array_column($aiProvidersApi->calls, 0));
  }

  public function testExpansionWithoutExpansionProviderUsesProvider(): void {
    $aiProvidersApi = $this->fakeAiProvidersApi();
    $service = $this->service('ai_providers_api', $aiProvidersApi, $this->spyLogger());

    $service->messageForOperation('expand_query', 'system', 'query', 512);

    $this->assertSame('claude', $aiProvidersApi->calls[0][0]);
  }

  /**
   * Builds a service with 'claude' selected as the AI Providers API provider.
   */
  private function service(string $provider, ?object $aiProvidersApi, object $logger, string $expansionProvider = ''): ScoltaAiService {
    $settings = new class($expansionProvider) {

      public function __construct(private string $expansionProvider) {}

      public function get(string $key = ''): mixed {
        return [
          'ai_providers_api_provider' => 'claude',
          'ai_providers_api_expansion_provider' => $this->expansionProvider,
        ][$key] ?? NULL;
      }

    };
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settings);

    $ref = new \ReflectionClass(ScoltaAiService::class);
    $service = $ref->newInstanceWithoutConstructor();
    (new \ReflectionProperty(AiServiceAdapter::class, 'config'))->setValue($service, ScoltaConfig::fromArray(['ai_provider' => $provider]));
    $ref->getProperty('configFactory')->setValue($service, $configFactory);
    $ref->getProperty('logger')->setValue($service, $logger);
    $ref->getProperty('aiProvidersApi')->setValue($service, $aiProvidersApi);
    return $service;
  }

  /**
   * Invokes a protected method on the service.
   */
  private function invoke(ScoltaAiService $service, string $method, array $args): mixed {
    return (new \ReflectionMethod($service, $method))->invokeArgs($service, $args);
  }

  /**
   * A fake AI Providers API service recording prompt() calls.
   */
  private function fakeAiProvidersApi(array $enabled = ['claude' => 'Claude'], ?\Throwable $throw = NULL): object {
    return new class($enabled, $throw) {

      public array $calls = [];

      public function __construct(private array $enabled, private ?\Throwable $throw) {}

      public function getEnabledProviders(): array {
        return $this->enabled;
      }

      public function prompt(string $providerId, string $prompt, array $options = []): string {
        $this->calls[] = [$providerId, $prompt, $options];
        if ($this->throw !== NULL) {
          throw $this->throw;
        }
        return 'GENERATED_TEXT';
      }

    };
  }

  /**
   * A logger recording every record it receives.
   */
  private function spyLogger(): object {
    return new class() extends AbstractLogger {

      public array $records = [];

      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
      }

    };
  }

}
