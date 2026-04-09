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
  private string $adapterFile;
  private string $adapterContents;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->serviceFile = $this->moduleRoot . '/src/Service/ScoltaAiService.php';
    $this->serviceContents = file_get_contents($this->serviceFile);

    // The base class lives in scolta-php.
    $this->adapterFile = $this->moduleRoot . '/vendor/tag1/scolta-php/src/Service/AiServiceAdapter.php';
    $this->adapterContents = file_get_contents($this->adapterFile);
  }

  // -------------------------------------------------------------------
  // Extends AiServiceAdapter.
  // -------------------------------------------------------------------

  public function testExtendsAiServiceAdapter(): void {
    $this->assertStringContainsString(
      'extends AiServiceAdapter',
      $this->serviceContents,
      'ScoltaAiService must extend AiServiceAdapter'
    );
  }

  public function testImportsAiServiceAdapter(): void {
    $this->assertStringContainsString(
      'use Tag1\Scolta\Service\AiServiceAdapter',
      $this->serviceContents,
      'ScoltaAiService must import AiServiceAdapter'
    );
  }

  // -------------------------------------------------------------------
  // getConfig() method (inherited from AiServiceAdapter).
  // -------------------------------------------------------------------

  public function testGetConfigMethodExistsInAdapter(): void {
    $this->assertStringContainsString(
      'function getConfig(): ScoltaConfig',
      $this->adapterContents,
      'AiServiceAdapter must have getConfig() returning ScoltaConfig'
    );
  }

  public function testGetConfigImportsScoltaConfig(): void {
    $this->assertStringContainsString(
      'use Tag1\Scolta\Config\ScoltaConfig',
      $this->serviceContents,
      'ScoltaAiService must import ScoltaConfig'
    );
  }

  public function testBuildConfigFlattensNestedScoringConfig(): void {
    $this->assertStringContainsString(
      "unset(\$values['scoring'])",
      $this->serviceContents,
      'buildConfig must flatten and remove scoring subarray'
    );
  }

  public function testBuildConfigFlattensNestedDisplayConfig(): void {
    $this->assertStringContainsString(
      "unset(\$values['display'])",
      $this->serviceContents,
      'buildConfig must flatten and remove display subarray'
    );
  }

  public function testBuildConfigRemovesPagefindConfig(): void {
    $this->assertStringContainsString(
      "unset(\$values['pagefind'])",
      $this->serviceContents,
      'buildConfig must remove pagefind config (not relevant to ScoltaConfig)'
    );
  }

  public function testBuildConfigInjectsApiKey(): void {
    $this->assertStringContainsString(
      "['ai_api_key'] = \$this->getApiKey()",
      $this->serviceContents,
      'buildConfig must inject API key from getApiKey()'
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
  // Prompt methods (inherited from AiServiceAdapter).
  // -------------------------------------------------------------------

  public function testGetExpandPromptMethodExistsInAdapter(): void {
    $this->assertStringContainsString(
      'function getExpandPrompt(): string',
      $this->adapterContents,
      'AiServiceAdapter must have getExpandPrompt()'
    );
  }

  public function testGetSummarizePromptMethodExistsInAdapter(): void {
    $this->assertStringContainsString(
      'function getSummarizePrompt(): string',
      $this->adapterContents,
      'AiServiceAdapter must have getSummarizePrompt()'
    );
  }

  public function testGetFollowUpPromptMethodExistsInAdapter(): void {
    $this->assertStringContainsString(
      'function getFollowUpPrompt(): string',
      $this->adapterContents,
      'AiServiceAdapter must have getFollowUpPrompt()'
    );
  }

  public function testPromptMethodsUseDefaultPromptsFallback(): void {
    $this->assertStringContainsString(
      'DefaultPrompts::EXPAND_QUERY',
      $this->adapterContents,
      'getExpandPrompt should fall back to DefaultPrompts::EXPAND_QUERY'
    );
    $this->assertStringContainsString(
      'DefaultPrompts::SUMMARIZE',
      $this->adapterContents,
      'getSummarizePrompt should fall back to DefaultPrompts::SUMMARIZE'
    );
    $this->assertStringContainsString(
      'DefaultPrompts::FOLLOW_UP',
      $this->adapterContents,
      'getFollowUpPrompt should fall back to DefaultPrompts::FOLLOW_UP'
    );
  }

  public function testPromptMethodsCheckForCustomOverride(): void {
    // All three prompt methods should check if a custom prompt is configured.
    $this->assertStringContainsString('promptExpandQuery', $this->adapterContents,
      'getExpandPrompt should check for custom promptExpandQuery');
    $this->assertStringContainsString('promptSummarize', $this->adapterContents,
      'getSummarizePrompt should check for custom promptSummarize');
    $this->assertStringContainsString('promptFollowUp', $this->adapterContents,
      'getFollowUpPrompt should check for custom promptFollowUp');
  }

  // -------------------------------------------------------------------
  // message() and conversation() methods (inherited from AiServiceAdapter).
  // -------------------------------------------------------------------

  public function testMessageMethodExistsInAdapter(): void {
    $this->assertStringContainsString(
      'function message(string $systemPrompt, string $userMessage, int $maxTokens',
      $this->adapterContents,
      'AiServiceAdapter must have message() with correct signature'
    );
  }

  public function testConversationMethodExistsInAdapter(): void {
    $this->assertStringContainsString(
      'function conversation(string $systemPrompt, array $messages, int $maxTokens',
      $this->adapterContents,
      'AiServiceAdapter must have conversation() with correct signature'
    );
  }

  public function testMessageReturnsString(): void {
    $this->assertStringContainsString(
      'public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string',
      $this->adapterContents,
      'message() must return string'
    );
  }

  public function testConversationReturnsString(): void {
    $this->assertStringContainsString(
      'public function conversation(string $systemPrompt, array $messages, int $maxTokens = 512): string',
      $this->adapterContents,
      'conversation() must return string'
    );
  }

  public function testMessageDefaultMaxTokensIs512(): void {
    $this->assertStringContainsString(
      '$maxTokens = 512',
      $this->adapterContents,
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

  public function testTryFrameworkAiChecksDrupalAiModule(): void {
    $this->assertStringContainsString(
      '$this->hasDrupalAiModule()',
      $this->serviceContents,
      'tryFrameworkAi() should check for Drupal AI module'
    );
  }

  public function testAdapterFallsBackToBuiltInClient(): void {
    $this->assertStringContainsString(
      '$this->getClient()->message(',
      $this->adapterContents,
      'AiServiceAdapter message() should fall back to built-in AiClient'
    );
  }

  public function testAdapterConversationFallsBackToBuiltInClient(): void {
    $this->assertStringContainsString(
      '$this->getClient()->conversation(',
      $this->adapterContents,
      'AiServiceAdapter conversation() should fall back to built-in AiClient'
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
  // Client lazy initialization (in AiServiceAdapter).
  // -------------------------------------------------------------------

  public function testGetClientMethodExistsInAdapter(): void {
    $this->assertStringContainsString(
      'function getClient(): AiClient',
      $this->adapterContents,
      'AiServiceAdapter must have getClient() returning AiClient'
    );
  }

  public function testClientIsLazilyInitialized(): void {
    $this->assertStringContainsString(
      '$this->client === null',
      $this->adapterContents,
      'getClient() should lazily initialize the AiClient'
    );
  }

  // -------------------------------------------------------------------
  // resolvePrompt helper (in AiServiceAdapter).
  // -------------------------------------------------------------------

  public function testResolvePromptMethodExistsInAdapter(): void {
    $this->assertStringContainsString(
      'function resolvePrompt(string $template): string',
      $this->adapterContents,
      'AiServiceAdapter must have resolvePrompt() method'
    );
  }

  public function testResolvePromptUsesDefaultPrompts(): void {
    $this->assertStringContainsString(
      'DefaultPrompts::resolve(',
      $this->adapterContents,
      'resolvePrompt should delegate to DefaultPrompts::resolve()'
    );
  }

  // -------------------------------------------------------------------
  // Site name fallback.
  // -------------------------------------------------------------------

  public function testBuildConfigFallsBackToSystemSiteName(): void {
    $this->assertStringContainsString(
      "system.site",
      $this->serviceContents,
      'buildConfig should fall back to Drupal system.site name'
    );
  }

  // -------------------------------------------------------------------
  // createClient uses Drupal HTTP client.
  // -------------------------------------------------------------------

  public function testCreateClientUsesDrupalHttpClient(): void {
    $this->assertStringContainsString(
      '$this->httpClient',
      $this->serviceContents,
      'createClient should inject the Drupal HTTP client'
    );
  }

}
