<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests ScoltaAiService contract via file inspection and reflection.
 *
 * Verifies method existence, signatures, return types, and service
 * wiring without requiring a Drupal bootstrap.
 */
class ScoltaAiServiceValidationTest extends TestCase {

  private string $moduleRoot;
  private string $serviceFile;
  private string $serviceContents;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->serviceFile = $this->moduleRoot . '/src/Service/ScoltaAiService.php';
    $this->serviceContents = file_get_contents($this->serviceFile);
  }

  // -------------------------------------------------------------------
  // getConfig() method.
  // -------------------------------------------------------------------

  public function testGetConfigMethodExists(): void {
    $this->assertStringContainsString(
      'function getConfig(): ScoltaConfig',
      $this->serviceContents,
      'ScoltaAiService must have getConfig() returning ScoltaConfig'
    );
  }

  public function testGetConfigImportsScoltaConfig(): void {
    $this->assertStringContainsString(
      'use Tag1\Scolta\Config\ScoltaConfig',
      $this->serviceContents,
      'ScoltaAiService must import ScoltaConfig'
    );
  }

  public function testGetConfigFlattensNestedScoringConfig(): void {
    $this->assertStringContainsString(
      "unset(\$values['scoring'])",
      $this->serviceContents,
      'getConfig must flatten and remove scoring subarray'
    );
  }

  public function testGetConfigFlattensNestedDisplayConfig(): void {
    $this->assertStringContainsString(
      "unset(\$values['display'])",
      $this->serviceContents,
      'getConfig must flatten and remove display subarray'
    );
  }

  public function testGetConfigRemovesPagefindConfig(): void {
    $this->assertStringContainsString(
      "unset(\$values['pagefind'])",
      $this->serviceContents,
      'getConfig must remove pagefind config (not relevant to ScoltaConfig)'
    );
  }

  public function testGetConfigInjectsApiKey(): void {
    $this->assertStringContainsString(
      "['ai_api_key'] = \$this->getApiKey()",
      $this->serviceContents,
      'getConfig must inject API key from getApiKey()'
    );
  }

  public function testGetConfigCachesResult(): void {
    // getConfig() should cache the ScoltaConfig instance.
    $this->assertStringContainsString(
      '$this->config === NULL',
      $this->serviceContents,
      'getConfig should lazily initialize and cache the config'
    );
  }

  // -------------------------------------------------------------------
  // getApiKey() method.
  // -------------------------------------------------------------------

  public function testGetApiKeyMethodExists(): void {
    $this->assertStringContainsString(
      'function getApiKey(): string',
      $this->serviceContents,
      'ScoltaAiService must have getApiKey() returning string'
    );
  }

  public function testGetApiKeyChecksEnvironmentVariable(): void {
    $this->assertStringContainsString(
      'SCOLTA_API_KEY',
      $this->serviceContents,
      'getApiKey should check SCOLTA_API_KEY environment variable'
    );
  }

  public function testGetApiKeyFallsBackToSettings(): void {
    $this->assertStringContainsString(
      "Settings::get('scolta.api_key'",
      $this->serviceContents,
      'getApiKey should fall back to Drupal Settings'
    );
  }

  // -------------------------------------------------------------------
  // getApiKeySource() method.
  // -------------------------------------------------------------------

  public function testGetApiKeySourceMethodExists(): void {
    $this->assertStringContainsString(
      'function getApiKeySource(): string',
      $this->serviceContents,
      'ScoltaAiService must have getApiKeySource() returning string'
    );
  }

  public function testGetApiKeySourceReturnsExpectedValues(): void {
    // Should return one of: 'env', 'settings', 'none'.
    $this->assertStringContainsString("return 'env'", $this->serviceContents,
      'getApiKeySource should return "env" when env var is set');
    $this->assertStringContainsString("return 'settings'", $this->serviceContents,
      'getApiKeySource should return "settings" when settings.php key exists');
    $this->assertStringContainsString("return 'none'", $this->serviceContents,
      'getApiKeySource should return "none" when no key is configured');
  }

  // -------------------------------------------------------------------
  // Prompt methods.
  // -------------------------------------------------------------------

  public function testGetExpandPromptMethodExists(): void {
    $this->assertStringContainsString(
      'function getExpandPrompt(): string',
      $this->serviceContents,
      'ScoltaAiService must have getExpandPrompt()'
    );
  }

  public function testGetSummarizePromptMethodExists(): void {
    $this->assertStringContainsString(
      'function getSummarizePrompt(): string',
      $this->serviceContents,
      'ScoltaAiService must have getSummarizePrompt()'
    );
  }

  public function testGetFollowUpPromptMethodExists(): void {
    $this->assertStringContainsString(
      'function getFollowUpPrompt(): string',
      $this->serviceContents,
      'ScoltaAiService must have getFollowUpPrompt()'
    );
  }

  public function testPromptMethodsUseDefaultPromptsFallback(): void {
    $this->assertStringContainsString(
      'DefaultPrompts::EXPAND_QUERY',
      $this->serviceContents,
      'getExpandPrompt should fall back to DefaultPrompts::EXPAND_QUERY'
    );
    $this->assertStringContainsString(
      'DefaultPrompts::SUMMARIZE',
      $this->serviceContents,
      'getSummarizePrompt should fall back to DefaultPrompts::SUMMARIZE'
    );
    $this->assertStringContainsString(
      'DefaultPrompts::FOLLOW_UP',
      $this->serviceContents,
      'getFollowUpPrompt should fall back to DefaultPrompts::FOLLOW_UP'
    );
  }

  public function testPromptMethodsCheckForCustomOverride(): void {
    // All three prompt methods should check if a custom prompt is configured.
    $this->assertStringContainsString('promptExpandQuery', $this->serviceContents,
      'getExpandPrompt should check for custom promptExpandQuery');
    $this->assertStringContainsString('promptSummarize', $this->serviceContents,
      'getSummarizePrompt should check for custom promptSummarize');
    $this->assertStringContainsString('promptFollowUp', $this->serviceContents,
      'getFollowUpPrompt should check for custom promptFollowUp');
  }

  // -------------------------------------------------------------------
  // message() and conversation() methods.
  // -------------------------------------------------------------------

  public function testMessageMethodExists(): void {
    $this->assertStringContainsString(
      'function message(string $systemPrompt, string $userMessage, int $maxTokens',
      $this->serviceContents,
      'ScoltaAiService must have message() with correct signature'
    );
  }

  public function testConversationMethodExists(): void {
    $this->assertStringContainsString(
      'function conversation(string $systemPrompt, array $messages, int $maxTokens',
      $this->serviceContents,
      'ScoltaAiService must have conversation() with correct signature'
    );
  }

  public function testMessageReturnsString(): void {
    $this->assertStringContainsString(
      'public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string',
      $this->serviceContents,
      'message() must return string'
    );
  }

  public function testConversationReturnsString(): void {
    $this->assertStringContainsString(
      'public function conversation(string $systemPrompt, array $messages, int $maxTokens = 512): string',
      $this->serviceContents,
      'conversation() must return string'
    );
  }

  public function testMessageDefaultMaxTokensIs512(): void {
    $this->assertStringContainsString(
      '$maxTokens = 512',
      $this->serviceContents,
      'Default max tokens should be 512'
    );
  }

  // -------------------------------------------------------------------
  // Drupal AI module integration.
  // -------------------------------------------------------------------

  public function testHasDrupalAiModuleMethodExists(): void {
    $this->assertStringContainsString(
      'function hasDrupalAiModule(): bool',
      $this->serviceContents,
      'ScoltaAiService must have hasDrupalAiModule() returning bool'
    );
  }

  public function testMessageTriesDrupalAiFirst(): void {
    $this->assertStringContainsString(
      '$this->hasDrupalAiModule()',
      $this->serviceContents,
      'message() should check for Drupal AI module before using built-in client'
    );
  }

  public function testMessageFallsBackToBuiltInClient(): void {
    $this->assertStringContainsString(
      '$this->getClient()->message(',
      $this->serviceContents,
      'message() should fall back to built-in AiClient'
    );
  }

  public function testConversationFallsBackToBuiltInClient(): void {
    $this->assertStringContainsString(
      '$this->getClient()->conversation(',
      $this->serviceContents,
      'conversation() should fall back to built-in AiClient'
    );
  }

  // -------------------------------------------------------------------
  // Constructor and service wiring.
  // -------------------------------------------------------------------

  public function testConstructorParameterCountMatchesServices(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml');
    $args = $services['services']['scolta.ai_service']['arguments'] ?? [];

    if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $this->serviceContents, $m)) {
      $params = array_filter(array_map('trim', explode(',', $m[1])));
      $this->assertEquals(
        count($params), count($args),
        'ScoltaAiService constructor param count must match service arguments'
      );
    }
    else {
      $this->fail('ScoltaAiService has no constructor');
    }
  }

  public function testConstructorAcceptsHttpClient(): void {
    $this->assertStringContainsString(
      'ClientInterface $httpClient',
      $this->serviceContents,
      'Constructor should accept GuzzleHttp ClientInterface'
    );
  }

  public function testConstructorAcceptsConfigFactory(): void {
    $this->assertStringContainsString(
      'ConfigFactoryInterface $configFactory',
      $this->serviceContents,
      'Constructor should accept ConfigFactoryInterface'
    );
  }

  public function testConstructorAcceptsLogger(): void {
    $this->assertStringContainsString(
      'LoggerInterface $logger',
      $this->serviceContents,
      'Constructor should accept LoggerInterface'
    );
  }

  // -------------------------------------------------------------------
  // Client lazy initialization.
  // -------------------------------------------------------------------

  public function testGetClientMethodExists(): void {
    $this->assertStringContainsString(
      'function getClient(): AiClient',
      $this->serviceContents,
      'ScoltaAiService must have getClient() returning AiClient'
    );
  }

  public function testClientIsLazilyInitialized(): void {
    $this->assertStringContainsString(
      '$this->client === NULL',
      $this->serviceContents,
      'getClient() should lazily initialize the AiClient'
    );
  }

  // -------------------------------------------------------------------
  // resolvePrompt helper.
  // -------------------------------------------------------------------

  public function testResolvePromptMethodExists(): void {
    $this->assertStringContainsString(
      'function resolvePrompt(string $template): string',
      $this->serviceContents,
      'ScoltaAiService must have resolvePrompt() method'
    );
  }

  public function testResolvePromptUsesDefaultPrompts(): void {
    $this->assertStringContainsString(
      'DefaultPrompts::resolve(',
      $this->serviceContents,
      'resolvePrompt should delegate to DefaultPrompts::resolve()'
    );
  }

  // -------------------------------------------------------------------
  // Site name fallback.
  // -------------------------------------------------------------------

  public function testGetConfigFallsBackToSystemSiteName(): void {
    $this->assertStringContainsString(
      "system.site",
      $this->serviceContents,
      'getConfig should fall back to Drupal system.site name'
    );
  }

}
