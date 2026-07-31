<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests that the Drupal AI module integration is opt-in, not auto-detected.
 *
 * Verifies via file inspection (no Drupal bootstrap) that:
 * - tryFrameworkAi() and tryFrameworkConversation() only activate when
 *   ai_provider is explicitly set to 'drupal_ai', NOT merely when the
 *   Drupal AI module is installed.
 * - buildConfig() does not inject Amazee credentials when 'drupal_ai'
 *   is the selected provider, preserving the admin's explicit choice.
 *
 * This guards against the bug where installing drupal/ai silently rerouted
 * Amazee.ai requests through the Drupal AI module's own provider/key config.
 */
class ScoltaAiServiceDrupalAiOptInTest extends TestCase {

  private string $serviceContents;

  protected function setUp(): void {
    $this->serviceContents = file_get_contents(
      dirname(__DIR__, 2) . '/src/Service/ScoltaAiService.php'
    );
  }

  // -------------------------------------------------------------------
  // tryFrameworkAi() — drupal_ai opt-in check is the primary guard
  // -------------------------------------------------------------------

  public function testTryFrameworkAiChecksProviderBeforeModule(): void {
    preg_match(
      '/protected function tryFrameworkAi\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString("'drupal_ai'", $body,
      "tryFrameworkAi() must check for 'drupal_ai' provider string to enable opt-in routing");
  }

  public function testTryFrameworkAiOptInCheckPrecedesModuleCheck(): void {
    // The provider check ('drupal_ai') must appear BEFORE the hasDrupalAiModule()
    // call. This ensures that non-drupal_ai providers (Amazee, Anthropic, OpenAI)
    // short-circuit without ever touching the module detection logic.
    preg_match(
      '/protected function tryFrameworkAi\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $drupalAiPos = strpos($body, "'drupal_ai'");
    $moduleCheckPos = strpos($body, '$this->hasDrupalAiModule()');

    $this->assertNotFalse($drupalAiPos,
      "tryFrameworkAi() must check for 'drupal_ai' provider");
    $this->assertNotFalse($moduleCheckPos,
      'tryFrameworkAi() must still call hasDrupalAiModule() as a secondary guard');
    $this->assertLessThan($moduleCheckPos, $drupalAiPos,
      "The 'drupal_ai' opt-in check must appear before hasDrupalAiModule() — the module check is a secondary guard only active when drupal_ai is explicitly selected");
  }

  public function testTryFrameworkAiUsesStrictInequalityForOptIn(): void {
    preg_match(
      '/protected function tryFrameworkAi\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString("!== 'drupal_ai'", $body,
      "tryFrameworkAi() must use '!== 'drupal_ai'' to return NULL for all non-drupal_ai providers");
  }

  // -------------------------------------------------------------------
  // tryFrameworkConversation() — same opt-in requirement
  // -------------------------------------------------------------------

  public function testTryFrameworkConversationChecksProviderBeforeModule(): void {
    preg_match(
      '/protected function tryFrameworkConversation\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString("'drupal_ai'", $body,
      "tryFrameworkConversation() must check for 'drupal_ai' provider string");
  }

  public function testTryFrameworkConversationOptInCheckPrecedesModuleCheck(): void {
    preg_match(
      '/protected function tryFrameworkConversation\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $drupalAiPos = strpos($body, "'drupal_ai'");
    $moduleCheckPos = strpos($body, '$this->hasDrupalAiModule()');

    $this->assertNotFalse($drupalAiPos,
      "tryFrameworkConversation() must check for 'drupal_ai' provider");
    $this->assertNotFalse($moduleCheckPos,
      'tryFrameworkConversation() must still call hasDrupalAiModule() as secondary guard');
    $this->assertLessThan($moduleCheckPos, $drupalAiPos,
      "The 'drupal_ai' opt-in check must appear before hasDrupalAiModule()");
  }

  public function testTryFrameworkConversationUsesStrictInequalityForOptIn(): void {
    preg_match(
      '/protected function tryFrameworkConversation\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString("!== 'drupal_ai'", $body,
      "tryFrameworkConversation() must use '!== 'drupal_ai'' to short-circuit for non-drupal_ai providers");
  }

  // -------------------------------------------------------------------
  // buildConfig() — Amazee credentials NOT injected when drupal_ai selected
  // -------------------------------------------------------------------

  public function testBuildConfigSkipsAmazeeInjectionForDrupalAiProvider(): void {
    // When the admin selects 'drupal_ai', buildConfig() must NOT overwrite
    // ai_provider with 'openai' and inject the Amazee LiteLLM token.
    // The Drupal AI module manages its own provider, key, and model.
    //
    // Verify that the Amazee credential injection is guarded by a provider check.
    $this->assertStringContainsString("!== 'drupal_ai'", $this->serviceContents,
      "buildConfig() must guard Amazee credential injection with a '!== drupal_ai' check");
  }

  public function testBuildConfigDrupalAiGuardPrecedesAmazeeCredentials(): void {
    // The drupal_ai exclusion check must come before the Amazee credentials lookup.
    // This ensures that selecting 'drupal_ai' prevents the Amazee token from
    // silently overwriting the provider back to 'openai'.
    $drupalAiGuardPos = strpos($this->serviceContents, "'drupal_ai'");
    // The credential lookup is the store read, not a state read: the store is
    // what decrypts the token that DrupalConfigStorage::store() encrypted.
    $amazeeCredsPos = strpos($this->serviceContents, '$this->amazeeConfigStorage?->load()');

    $this->assertNotFalse($drupalAiGuardPos,
      "buildConfig() must reference 'drupal_ai' to guard Amazee injection");
    $this->assertNotFalse($amazeeCredsPos,
      'buildConfig() must still look up stored Amazee credentials for the built-in provider paths');
    $this->assertLessThan($amazeeCredsPos, $drupalAiGuardPos,
      "The 'drupal_ai' guard must appear before the Amazee credentials lookup in buildConfig()");
  }

  public function testBuildConfigGuardsAmazeeOnTheSelectedProvider(): void {
    // Eligibility is an input to the shared resolver rather than a branch
    // here, so every surface that reports on the key sees the same rule the
    // config path applies instead of each re-deriving it (scolta-php#252).
    // The rule is now the selected provider: 'amazee' is the only value that
    // lets the managed gateway into AI traffic, which excludes 'drupal_ai'
    // along with every other provider an operator can choose.
    $this->assertStringContainsString(
      "amazeeEligible: \$provider === 'amazee',",
      $this->serviceContents,
      'resolveApiKey() must make the managed gateway eligible only for the provider that selects it'
    );
    $this->assertStringContainsString(
      'if ($resolved->isAmazee()) {',
      $this->serviceContents,
      'buildConfig() must inject Amazee settings only when the resolution says Amazee won'
    );
  }

  // -------------------------------------------------------------------
  // Regression: warning logged when drupal_ai selected but module absent
  // -------------------------------------------------------------------

  public function testTryFrameworkAiLogsWarningWhenModuleMissing(): void {
    // When drupal_ai is selected but the module is not installed, a warning
    // must be logged so admins can diagnose the fallback.
    preg_match(
      '/protected function tryFrameworkAi\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString('$this->logger->warning(', $body,
      'tryFrameworkAi() must log a warning when the Drupal AI module is not installed');
  }

  public function testTryFrameworkConversationLogsWarningWhenModuleMissing(): void {
    preg_match(
      '/protected function tryFrameworkConversation\(.*?\{(.*?)\n  \}/s',
      $this->serviceContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString('$this->logger->warning(', $body,
      'tryFrameworkConversation() must log a warning when the Drupal AI module is not installed');
  }

}
