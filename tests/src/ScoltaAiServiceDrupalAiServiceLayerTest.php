<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests the Drupal AI module service-layer integration in ScoltaAiService.
 *
 * Verifies via file inspection (no Drupal bootstrap) that:
 * - messageViaDrupalAi() and conversationViaDrupalAi() use the Drupal AI
 *   module's configured default provider (via getDefaultProviderForOperationType)
 *   rather than creating an instance with Scolta's own provider config.
 * - Neither method passes Scolta's aiModel to the provider — model selection
 *   is delegated to the Drupal AI module's own configuration.
 * - Both methods throw when no default provider is configured.
 *
 * This guards against the previous bug where createInstance($config->aiProvider)
 * was called with 'drupal_ai' as the plugin ID — a value that is not a valid
 * Drupal AI provider plugin and would fail at runtime.
 */
class ScoltaAiServiceDrupalAiServiceLayerTest extends TestCase {

  private string $serviceContents;

  protected function setUp(): void {
    $this->serviceContents = file_get_contents(
      dirname(__DIR__, 2) . '/src/Service/ScoltaAiService.php'
    );
  }

  // -------------------------------------------------------------------
  // messageViaDrupalAi() — service-layer dispatch
  // -------------------------------------------------------------------

  public function testMessageViaDrupalAiUsesGetDefaultProvider(): void {
    $this->assertStringContainsString(
      'getDefaultProviderForOperationType(',
      $this->serviceContents,
      'messageViaDrupalAi() must use getDefaultProviderForOperationType() to find the site-configured default provider'
    );
  }

  public function testMessageViaDrupalAiPassesChatOperationType(): void {
    $this->assertStringContainsString(
      "getDefaultProviderForOperationType('chat')",
      $this->serviceContents,
      "messageViaDrupalAi() must request the default provider for 'chat' operations"
    );
  }

  public function testMessageViaDrupalAiPassesEmptyModelString(): void {
    // Passing '' as the model lets the provider use its configured default.
    // Passing $config->aiModel would inject Scolta's model config into the
    // Drupal AI module's provider, overriding the admin's choice there.
    $this->assertStringContainsString(
      "->chat(\$input, '', ",
      $this->serviceContents,
      "messageViaDrupalAi() must pass '' as model so the Drupal AI provider uses its own configured default"
    );
  }

  public function testMessageViaDrupalAiThrowsWhenNoDefaultProvider(): void {
    // When getDefaultProviderForOperationType returns empty, messageViaDrupalAi
    // must throw a RuntimeException so tryFrameworkAi()'s catch block can log
    // a warning and fall back to the built-in client.
    $this->assertStringContainsString(
      "throw new \\RuntimeException(",
      $this->serviceContents,
      'messageViaDrupalAi() must throw RuntimeException when no default provider is configured'
    );
  }

  public function testMessageViaDrupalAiNoLongerUsesScoltaProviderConfig(): void {
    // The old code called createInstance($config->aiProvider) which would pass
    // 'drupal_ai' as a plugin ID — not a valid Drupal AI provider. Verify the
    // new code does NOT reference $config->aiProvider in the context of createInstance.
    preg_match(
      '/protected function messageViaDrupalAi\(.*?\{(.*?)(?=\n  protected function|\n  public function|\n})/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringNotContainsString(
      'createInstance($config->aiProvider)',
      $body,
      'messageViaDrupalAi() must not pass $config->aiProvider to createInstance() — this would fail since "drupal_ai" is not a valid Drupal AI plugin ID'
    );
  }

  // -------------------------------------------------------------------
  // conversationViaDrupalAi() — same service-layer requirements
  // -------------------------------------------------------------------

  public function testConversationViaDrupalAiUsesGetDefaultProvider(): void {
    preg_match(
      '/protected function conversationViaDrupalAi\(.*?\{(.*?)(?=\n  protected function|\n  public function|\n})/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      'getDefaultProviderForOperationType(',
      $body,
      'conversationViaDrupalAi() must use getDefaultProviderForOperationType() to find the default provider'
    );
  }

  public function testConversationViaDrupalAiPassesEmptyModelString(): void {
    preg_match(
      '/protected function conversationViaDrupalAi\(.*?\{(.*?)(?=\n  protected function|\n  public function|\n})/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      "->chat(\$input, '', ",
      $body,
      "conversationViaDrupalAi() must pass '' as model so the Drupal AI provider uses its own default"
    );
  }

  public function testConversationViaDrupalAiThrowsWhenNoDefaultProvider(): void {
    preg_match(
      '/protected function conversationViaDrupalAi\(.*?\{(.*?)(?=\n  protected function|\n  public function|\n})/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      'throw new \\RuntimeException(',
      $body,
      'conversationViaDrupalAi() must throw RuntimeException when no default provider is configured'
    );
  }

  // -------------------------------------------------------------------
  // Service uses ai.provider plugin manager (not a direct 'ai' service)
  // -------------------------------------------------------------------

  public function testServiceUsesAiProviderPluginManager(): void {
    $this->assertStringContainsString(
      "service('ai.provider')",
      $this->serviceContents,
      'messageViaDrupalAi/conversationViaDrupalAi must use the ai.provider plugin manager service'
    );
  }

}
