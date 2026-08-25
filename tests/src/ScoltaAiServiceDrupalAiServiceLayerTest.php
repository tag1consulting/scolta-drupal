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
 * - The array returned by getDefaultProviderForOperationType() is unpacked:
 *   its 'provider_id' is passed to createInstance() and its 'model_id' to
 *   chat() — the raw array is never handed to createInstance().
 * - Both methods throw when no default provider is configured.
 *
 * This guards against two runtime bugs:
 * - createInstance($config->aiProvider) with 'drupal_ai' as the plugin ID —
 *   a value that is not a valid Drupal AI provider plugin.
 * - Passing the whole getDefaultProviderForOperationType() return value to
 *   createInstance(). That method returns an array
 *   (['provider_id' => ..., 'model_id' => ...]), not a string; passing the
 *   array throws "Cannot access offset of type array" in the plugin manager,
 *   which broke every AI feature on the drupal_ai path (issue #163).
 */
class ScoltaAiServiceDrupalAiServiceLayerTest extends TestCase {

  private string $serviceContents;

  protected function setUp(): void {
    $this->serviceContents = file_get_contents(
      dirname(__DIR__, 2) . '/modules/scolta_ui/src/Service/ScoltaAiService.php'
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

  public function testMessageViaDrupalAiPassesResolvedModelId(): void {
    // getDefaultProviderForOperationType('chat') returns
    // ['provider_id' => ..., 'model_id' => ...]. The resolved model_id (the
    // admin's own choice in the Drupal AI module) must be passed to chat();
    // Scolta's own aiModel is never injected.
    $this->assertStringContainsString(
      "->chat(\$input, \$default['model_id'] ?? '', ",
      $this->serviceContents,
      "messageViaDrupalAi() must pass the resolved model_id from the default-provider array to chat()"
    );
  }

  public function testMessageViaDrupalAiUnpacksProviderIdForCreateInstance(): void {
    // getDefaultProviderForOperationType() returns an array, not a string.
    // createInstance() must receive the unpacked 'provider_id', never the
    // raw array (which throws "Cannot access offset of type array"). See #163.
    preg_match(
      '/protected function messageViaDrupalAi\(.*?\{(.*?)(?=\n  protected function|\n  public function|\n})/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      "createInstance(\$default['provider_id'])",
      $body,
      "messageViaDrupalAi() must pass the unpacked 'provider_id' string to createInstance()"
    );
    $this->assertStringNotContainsString(
      'createInstance($defaultProviderId)',
      $body,
      'messageViaDrupalAi() must not pass the raw getDefaultProviderForOperationType() array to createInstance() — it returns an array, not a plugin ID string (#163)'
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

  public function testConversationViaDrupalAiPassesResolvedModelId(): void {
    preg_match(
      '/protected function conversationViaDrupalAi\(.*?\{(.*?)(?=\n  protected function|\n  public function|\n})/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      "->chat(\$input, \$default['model_id'] ?? '', ",
      $body,
      "conversationViaDrupalAi() must pass the resolved model_id from the default-provider array to chat()"
    );
  }

  public function testConversationViaDrupalAiUnpacksProviderIdForCreateInstance(): void {
    preg_match(
      '/protected function conversationViaDrupalAi\(.*?\{(.*?)(?=\n  protected function|\n  public function|\n})/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      "createInstance(\$default['provider_id'])",
      $body,
      "conversationViaDrupalAi() must pass the unpacked 'provider_id' string to createInstance()"
    );
    $this->assertStringNotContainsString(
      'createInstance($defaultProviderId)',
      $body,
      'conversationViaDrupalAi() must not pass the raw getDefaultProviderForOperationType() array to createInstance() (#163)'
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
      '$this->aiProviderManager',
      $this->serviceContents,
      'messageViaDrupalAi/conversationViaDrupalAi must use the injected ai.provider plugin manager'
    );
    $this->assertStringNotContainsString(
      "\Drupal::service('ai.provider')",
      $this->serviceContents,
      'The ai.provider plugin manager must be injected (@?ai.provider), not fetched statically'
    );
  }

}
