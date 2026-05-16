<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests ScoltaSettingsForm changes for the Drupal AI provider option.
 *
 * Verifies via file inspection (no Drupal bootstrap) that:
 * - The 'drupal_ai' provider option is conditionally added based on module
 *   availability via hasDrupalAiModule().
 * - Model, API key, expansion model, and base URL fields have #states to
 *   hide them when 'drupal_ai' is selected.
 * - The $defaultProvider calculation respects a stored 'drupal_ai' selection
 *   even when Amazee credentials are active.
 * - The status display reflects the opt-in behavior (not auto-detect).
 */
class ScoltaSettingsFormDrupalAiTest extends TestCase {

  private string $formContents;

  protected function setUp(): void {
    $this->formContents = file_get_contents(
      dirname(__DIR__, 2) . '/src/Form/ScoltaSettingsForm.php'
    );
  }

  // -------------------------------------------------------------------
  // 'drupal_ai' option in provider dropdown
  // -------------------------------------------------------------------

  public function testFormIncludesDrupalAiOption(): void {
    $this->assertStringContainsString(
      "'drupal_ai'",
      $this->formContents,
      "ScoltaSettingsForm must include 'drupal_ai' as a provider option"
    );
  }

  public function testDrupalAiOptionConditionalOnModulePresence(): void {
    // The option must only be added when hasDrupalAiModule() returns true.
    // This prevents the option from appearing on sites without the AI module.
    $this->assertStringContainsString(
      'hasDrupalAiModule()',
      $this->formContents,
      "The 'drupal_ai' option must be conditional on hasDrupalAiModule()"
    );
  }

  public function testDrupalAiOptionGuardedByIfStatement(): void {
    // Verify the form has an if block around adding 'drupal_ai' to options.
    $this->assertMatchesRegularExpression(
      '/if\s*\(\s*\$this->aiService->hasDrupalAiModule\(\)\s*\)/',
      $this->formContents,
      "Provider options must be guarded by 'if (\$this->aiService->hasDrupalAiModule())'"
    );
  }

  // -------------------------------------------------------------------
  // $defaultProvider respects stored 'drupal_ai'
  // -------------------------------------------------------------------

  public function testDefaultProviderRespectsDrupalAiSelection(): void {
    // When the admin stored 'drupal_ai' in config, that must be the default
    // even if Amazee credentials exist. isAmazeeActive() must NOT override it.
    $this->assertStringContainsString(
      "=== 'drupal_ai'",
      $this->formContents,
      "Form must check if stored provider is 'drupal_ai' before applying Amazee default"
    );
  }

  public function testDefaultProviderDrupalAiCheckPrecedesAmazeeCheck(): void {
    $drupalAiCheckPos = strpos($this->formContents, "'drupal_ai'");
    $amazeeCheckPos = strpos($this->formContents, 'isAmazeeActive()');

    $this->assertNotFalse($drupalAiCheckPos,
      "Form must reference 'drupal_ai' when setting the default provider");
    $this->assertNotFalse($amazeeCheckPos,
      'Form must still call isAmazeeActive() for non-drupal_ai providers');
    $this->assertLessThan($amazeeCheckPos, $drupalAiCheckPos,
      "The 'drupal_ai' check must appear before isAmazeeActive() in default provider logic");
  }

  // -------------------------------------------------------------------
  // #states: model, expansion model, base URL hidden when drupal_ai selected
  // -------------------------------------------------------------------

  public function testAiModelHiddenWhenDrupalAiSelected(): void {
    // Extract the ai_model field definition and verify it has #states.
    $this->assertMatchesRegularExpression(
      "/'ai_model'.*?'#states'.*?'drupal_ai'/s",
      $this->formContents,
      "ai_model field must have #states to hide it when 'drupal_ai' is selected"
    );
  }

  public function testAiExpansionModelHiddenWhenDrupalAiSelected(): void {
    $this->assertMatchesRegularExpression(
      "/'ai_expansion_model'.*?'#states'.*?'drupal_ai'/s",
      $this->formContents,
      "ai_expansion_model field must have #states to hide it when 'drupal_ai' is selected"
    );
  }

  public function testAiBaseUrlHiddenWhenDrupalAiSelected(): void {
    $this->assertMatchesRegularExpression(
      "/'ai_base_url'.*?'#states'.*?'drupal_ai'/s",
      $this->formContents,
      "ai_base_url field must have #states to hide it when 'drupal_ai' is selected"
    );
  }

  public function testApiKeyStatusHiddenWhenDrupalAiSelected(): void {
    // API key status is irrelevant when the Drupal AI module manages its own keys.
    $this->assertMatchesRegularExpression(
      "/'api_key_status'.*?'#states'.*?'drupal_ai'|api_key_status.*?states.*?drupal_ai/s",
      $this->formContents,
      "api_key_status must be hidden when 'drupal_ai' is selected"
    );
  }

  public function testFieldsUseInvisibleNotHiddenState(): void {
    // 'invisible' hides with CSS (preserving the value) while 'hidden' removes
    // the element. For fields that save their values, 'invisible' is correct.
    $this->assertStringContainsString(
      "'invisible'",
      $this->formContents,
      "Fields hidden by drupal_ai selection must use '#states' => ['invisible' => ...], not 'hidden'"
    );
  }

  // -------------------------------------------------------------------
  // Drupal AI info element shown when drupal_ai selected
  // -------------------------------------------------------------------

  public function testFormHasDrupalAiInfoElement(): void {
    $this->assertStringContainsString(
      'ai_provider_drupal_ai_info',
      $this->formContents,
      "Form must have an info element for the 'drupal_ai' provider option"
    );
  }

  public function testDrupalAiInfoElementHasVisibleState(): void {
    $this->assertStringContainsString(
      'ai_provider_drupal_ai_info',
      $this->formContents,
      "The drupal_ai info element must exist in the form"
    );
    // Verify it has a visible state tied to the drupal_ai selection.
    $this->assertMatchesRegularExpression(
      "/ai_provider_drupal_ai_info.*?'visible'.*?'drupal_ai'/s",
      $this->formContents,
      "drupal_ai info element must only be visible when 'drupal_ai' is selected"
    );
  }

  // -------------------------------------------------------------------
  // Status display reflects opt-in behavior
  // -------------------------------------------------------------------

  public function testStatusInfoChecksActiveProviderConfig(): void {
    // The status display must check the stored ai_provider config value,
    // not just module presence. This ensures accurate status for all provider paths.
    $this->assertStringContainsString(
      "get('ai_provider')",
      $this->formContents,
      "buildStatusInfo() must read 'ai_provider' from config to determine which status to show"
    );
  }

  public function testStatusInfoHandlesDrupalAiProvider(): void {
    // buildStatusInfo() must have a drupal_ai case that shows a relevant message.
    preg_match(
      '/function buildStatusInfo\(\)[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s',
      $this->formContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString("'drupal_ai'", $body,
      "buildStatusInfo() must handle the 'drupal_ai' provider case explicitly");
  }

  public function testStatusNoLongerAutoDetectsModule(): void {
    // The old status message was "Drupal AI module detected — requests will
    // route through ai.provider service." This was wrong because it implied
    // auto-routing, which is no longer the case. Verify this message is gone.
    $this->assertStringNotContainsString(
      'Drupal AI module detected',
      $this->formContents,
      'Status display must not say "Drupal AI module detected" — routing is now opt-in, not auto-detect'
    );
  }

}
