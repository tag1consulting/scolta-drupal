<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests ScoltaAiService fallback logic via file inspection and reflection.
 *
 * Verifies the dual-path AI routing structure without requiring a Drupal
 * bootstrap. The service routes through the Drupal AI module when available
 * and falls back to the built-in AiClient otherwise.
 */
class ScoltaAiServiceFallbackTest extends TestCase {

  private string $moduleRoot;
  private string $serviceFile;
  private string $serviceContents;
  private string $adapterFile;
  private string $adapterContents;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->serviceFile = $this->moduleRoot . '/src/Service/ScoltaAiService.php';
    $this->serviceContents = file_get_contents($this->serviceFile);

    $this->adapterFile = $this->moduleRoot . '/vendor/tag1/scolta-php/src/Service/AiServiceAdapter.php';
    $this->adapterContents = file_get_contents($this->adapterFile);
  }

  // -------------------------------------------------------------------
  // tryFrameworkAi() — method signature and return type
  // -------------------------------------------------------------------

  public function testTryFrameworkAiExists(): void {
    // We test via file inspection since ScoltaAiService needs Drupal bootstrap.
    $this->assertStringContainsString(
      'function tryFrameworkAi(',
      $this->serviceContents,
      'ScoltaAiService must have tryFrameworkAi() method'
    );
  }

  public function testTryFrameworkAiIsProtected(): void {
    $this->assertMatchesRegularExpression(
      '/protected function tryFrameworkAi\(/',
      $this->serviceContents,
      'tryFrameworkAi() must be protected (overrides AiServiceAdapter abstract method)'
    );
  }

  public function testTryFrameworkAiReturnsNullableString(): void {
    // The return type must be ?string so null triggers fallback in AiServiceAdapter.
    $this->assertStringContainsString(
      'protected function tryFrameworkAi(string $systemPrompt, string $userMessage, int $maxTokens): ?string',
      $this->serviceContents,
      'tryFrameworkAi() must return ?string (null = trigger fallback to built-in client)'
    );
  }

  // -------------------------------------------------------------------
  // tryFrameworkConversation() — method signature and return type
  // -------------------------------------------------------------------

  public function testTryFrameworkConversationExists(): void {
    $this->assertStringContainsString(
      'function tryFrameworkConversation(',
      $this->serviceContents,
      'ScoltaAiService must have tryFrameworkConversation() method'
    );
  }

  public function testTryFrameworkConversationIsProtected(): void {
    $this->assertMatchesRegularExpression(
      '/protected function tryFrameworkConversation\(/',
      $this->serviceContents,
      'tryFrameworkConversation() must be protected'
    );
  }

  public function testTryFrameworkConversationReturnsNullableString(): void {
    $this->assertStringContainsString(
      'protected function tryFrameworkConversation(string $systemPrompt, array $messages, int $maxTokens): ?string',
      $this->serviceContents,
      'tryFrameworkConversation() must return ?string (null = trigger fallback)'
    );
  }

  // -------------------------------------------------------------------
  // hasDrupalAiModule() — checks the service container
  // -------------------------------------------------------------------

  public function testHasDrupalAiModuleChecksServiceContainer(): void {
    $this->assertStringContainsString(
      "hasService('ai.provider')",
      $this->serviceContents,
      "hasDrupalAiModule() must check \\Drupal::hasService('ai.provider')"
    );
  }

  public function testHasDrupalAiModuleReturnsBool(): void {
    $this->assertStringContainsString(
      'function hasDrupalAiModule(): bool',
      $this->serviceContents,
      'hasDrupalAiModule() must return bool'
    );
  }

  // -------------------------------------------------------------------
  // Fallback trigger: null return → AiServiceAdapter uses built-in client
  // -------------------------------------------------------------------

  public function testAdapterCallsTryFrameworkAiFirst(): void {
    // AiServiceAdapter::message() must call tryFrameworkAi() before getClient().
    $this->assertStringContainsString(
      'tryFrameworkAi(',
      $this->adapterContents,
      'AiServiceAdapter::message() must call tryFrameworkAi() first'
    );
  }

  public function testAdapterFallsBackWhenTryFrameworkAiReturnsNull(): void {
    // Verify the adapter falls back: if tryFrameworkAi returns null,
    // getClient()->message() is called.
    $this->assertStringContainsString(
      '$this->getClient()->message(',
      $this->adapterContents,
      'AiServiceAdapter must fall back to getClient()->message() when tryFrameworkAi returns null'
    );
  }

  public function testAdapterCallsTryFrameworkConversationFirst(): void {
    $this->assertStringContainsString(
      'tryFrameworkConversation(',
      $this->adapterContents,
      'AiServiceAdapter::conversation() must call tryFrameworkConversation() first'
    );
  }

  public function testAdapterConversationFallsBackToBuiltInClient(): void {
    $this->assertStringContainsString(
      '$this->getClient()->conversation(',
      $this->adapterContents,
      'AiServiceAdapter must fall back to getClient()->conversation() when tryFrameworkConversation returns null'
    );
  }

  // -------------------------------------------------------------------
  // Exception handling: catch blocks log warning and return null
  // -------------------------------------------------------------------

  public function testTryFrameworkAiCatchesExceptions(): void {
    // Extract tryFrameworkAi body and verify it has a catch block.
    preg_match(
      '/protected function tryFrameworkAi\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString('catch (', $body,
      'tryFrameworkAi() must have a catch block');
  }

  public function testTryFrameworkAiCatchLogsWarning(): void {
    preg_match(
      '/protected function tryFrameworkAi\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString('$this->logger->warning(', $body,
      'tryFrameworkAi() catch must log a warning before returning null');
  }

  public function testTryFrameworkAiCatchReturnsNull(): void {
    preg_match(
      '/protected function tryFrameworkAi\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString('return NULL', $body,
      'tryFrameworkAi() catch must return NULL to trigger fallback');
  }

  public function testTryFrameworkConversationCatchLogsWarning(): void {
    preg_match(
      '/protected function tryFrameworkConversation\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString('$this->logger->warning(', $body,
      'tryFrameworkConversation() catch must log a warning before returning null');
  }

  public function testTryFrameworkConversationCatchReturnsNull(): void {
    preg_match(
      '/protected function tryFrameworkConversation\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString('return NULL', $body,
      'tryFrameworkConversation() catch must return NULL to trigger fallback');
  }

  // -------------------------------------------------------------------
  // createClient() — uses Drupal HTTP client (not a bare GuzzleClient)
  // -------------------------------------------------------------------

  public function testCreateClientMethodExists(): void {
    $this->assertStringContainsString(
      'function createClient(): AiClient',
      $this->serviceContents,
      'ScoltaAiService must override createClient() to inject the Drupal HTTP client'
    );
  }

  public function testCreateClientInjectsDrupalHttpClient(): void {
    preg_match(
      '/protected function createClient\(\): AiClient \{(.*?)\}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString('$this->httpClient', $body,
      'createClient() must pass $this->httpClient to AiClient (not create a new Guzzle client)');
  }

  // -------------------------------------------------------------------
  // Both tryFramework* methods guard with hasDrupalAiModule()
  // -------------------------------------------------------------------

  public function testTryFrameworkAiGuardsWithHasDrupalAiModule(): void {
    preg_match(
      '/protected function tryFrameworkAi\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString('$this->hasDrupalAiModule()', $body,
      'tryFrameworkAi() must check hasDrupalAiModule() before attempting Drupal AI call');
  }

  public function testTryFrameworkConversationGuardsWithHasDrupalAiModule(): void {
    preg_match(
      '/protected function tryFrameworkConversation\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString('$this->hasDrupalAiModule()', $body,
      'tryFrameworkConversation() must check hasDrupalAiModule() before attempting Drupal AI call');
  }

}
