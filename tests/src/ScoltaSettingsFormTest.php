<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Validates the ScoltaSettingsForm and the full config pipeline.
 *
 * Tests verify that:
 * 1. The form class is structurally compatible with Drupal 11's ConfigFormBase.
 * 2. Every form field maps to a config key in the install defaults.
 * 3. Every config key actually reaches ScoltaConfig and affects behavior.
 * 4. The JS scoring output changes when config values change.
 * 5. AI feature toggles flow through to ScoltaConfig correctly.
 */
class ScoltaSettingsFormTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // 1. Form class structural compatibility.
  // -------------------------------------------------------------------

  /**
   * Verifies the constructor accepts TypedConfigManagerInterface (Drupal 11).
   *
   * This catches the exact bug where ConfigFormBase::__construct() was called
   * with only one argument instead of two.
   */
  public function testConstructorAcceptsTypedConfigManager(): void {
    $file = $this->moduleRoot . '/src/Form/ScoltaSettingsForm.php';
    $contents = file_get_contents($file);

    // Must import TypedConfigManagerInterface.
    $this->assertStringContainsString(
      'use Drupal\Core\Config\TypedConfigManagerInterface',
      $contents,
      'ScoltaSettingsForm must import TypedConfigManagerInterface for Drupal 11 compatibility'
    );

    // Constructor must accept TypedConfigManagerInterface as a parameter.
    $this->assertMatchesRegularExpression(
      '/function\s+__construct\s*\([^)]*TypedConfigManagerInterface/s',
      $contents,
      'Constructor must accept TypedConfigManagerInterface parameter'
    );

    // parent::__construct must receive two arguments.
    $this->assertMatchesRegularExpression(
      '/parent::__construct\s*\(\s*\$\w+\s*,\s*\$\w+\s*\)/',
      $contents,
      'parent::__construct() must pass both $configFactory and $typedConfigManager'
    );
  }

  /**
   * Verifies create() passes typed config from the container.
   */
  public function testCreatePassesTypedConfigFromContainer(): void {
    $file = $this->moduleRoot . '/src/Form/ScoltaSettingsForm.php';
    $contents = file_get_contents($file);

    $this->assertStringContainsString(
      "config.typed",
      $contents,
      'create() must inject config.typed service for TypedConfigManagerInterface'
    );
  }

  /**
   * Methods with ': string' return type must not return $this->t() uncasted.
   *
   * Drupal's $this->t() returns TranslatableMarkup, not string. Returning
   * it from a method typed ': string' causes a TypeError at runtime.
   * This test catches the pattern statically.
   */
  public function testStringReturnMethodsDoNotReturnTranslatableMarkupUncasted(): void {
    $file = $this->moduleRoot . '/src/Form/ScoltaSettingsForm.php';
    $contents = file_get_contents($file);

    // Find all methods with ': string' return type.
    preg_match_all(
      '/function\s+(\w+)\([^)]*\)\s*:\s*string\s*\{(.*?)\n  \}/s',
      $contents,
      $matches,
      PREG_SET_ORDER,
    );

    $violations = [];
    foreach ($matches as $match) {
      $method = $match[1];
      $body = $match[2];

      // Look for 'return $this->t(' without a (string) cast.
      if (preg_match('/return\s+\$this->t\s*\(/', $body)) {
        $violations[] = $method;
      }
    }

    $this->assertEmpty(
      $violations,
      'Methods with : string return type must cast $this->t() with (string): '
      . implode(', ', $violations)
    );
  }

  /**
   * Verifies getDefaultPrompt shows a warning message on WASM failure.
   *
   * When WASM fails to load, the method should NOT return an empty
   * string — it should return a visible warning message telling the
   * admin to run check-setup.
   */
  public function testGetDefaultPromptShowsWarningOnFailure(): void {
    $file = $this->moduleRoot . '/src/Form/ScoltaSettingsForm.php';
    $contents = file_get_contents($file);

    // The catch block should NOT return empty string.
    $this->assertStringNotContainsString(
      "return '';",
      // Extract just the getDefaultPrompt method body.
      $this->extractMethod($contents, 'getDefaultPrompt'),
      'getDefaultPrompt catch block should not return empty string'
    );

    // Should contain a user-visible message.
    $this->assertStringContainsString(
      'check-setup',
      $this->extractMethod($contents, 'getDefaultPrompt'),
      'getDefaultPrompt catch should mention check-setup command'
    );

    // Should log the error.
    $this->assertStringContainsString(
      'getLogger',
      $this->extractMethod($contents, 'getDefaultPrompt'),
      'getDefaultPrompt should log warning when WASM fails'
    );
  }

  /**
   * Extract a method body from PHP source.
   */
  private function extractMethod(string $source, string $methodName): string {
    $pattern = '/function\s+' . preg_quote($methodName) . '\s*\([^)]*\)[^{]*\{(.*?)\n  \}/s';
    if (preg_match($pattern, $source, $m)) {
      return $m[1];
    }
    return '';
  }

  // -------------------------------------------------------------------
  // 2. Form fields map to install config keys.
  // -------------------------------------------------------------------

  /**
   * Every config key in the install defaults should be settable via the form.
   */
  public function testFormSubmitCoversAllInstallConfigKeys(): void {
    $installDefaults = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $formFile = file_get_contents($this->moduleRoot . '/src/Form/ScoltaSettingsForm.php');

    // Flatten nested keys to dot notation (scoring.title_match_boost, etc.)
    $configKeys = $this->flattenKeys($installDefaults);

    // These keys are intentionally not in the form (read-only or handled elsewhere).
    $excluded = [
      'pagefind.build_dir',
      'pagefind.output_dir',
      'pagefind.binary',
      'pagefind.auto_rebuild',
      'pagefind.view_mode',
    ];

    foreach ($configKeys as $key) {
      if (in_array($key, $excluded, true)) {
        continue;
      }

      // The submitForm method should contain ->set('key', ...) for this key.
      $this->assertStringContainsString(
        "'{$key}'",
        $formFile,
        "Config key '{$key}' from install defaults is not referenced in ScoltaSettingsForm"
      );
    }
  }

  // -------------------------------------------------------------------
  // 3. Config values reach ScoltaConfig and affect its properties.
  // -------------------------------------------------------------------

  /**
   * Changing scoring config values produces different ScoltaConfig properties.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('scoringOverrideProvider')]
  public function testScoringConfigOverridesAffectScoltaConfig(
    string $configKey,
    mixed $customValue,
    string $propertyName,
  ): void {
    $defaults = $this->getInstallDefaults();
    $defaultConfig = $this->simulateGetConfig($defaults);
    $defaultValue = $defaultConfig->$propertyName;

    // Apply the override.
    $modified = $defaults;
    $this->setNestedValue($modified, $configKey, $customValue);
    $modifiedConfig = $this->simulateGetConfig($modified);

    $this->assertNotEquals(
      $defaultValue,
      $modifiedConfig->$propertyName,
      "Changing config '{$configKey}' to {$customValue} should change ScoltaConfig::\${$propertyName}"
    );
    $this->assertEquals(
      $customValue,
      $modifiedConfig->$propertyName,
      "ScoltaConfig::\${$propertyName} should equal the overridden value"
    );
  }

  public static function scoringOverrideProvider(): array {
    return [
      'title_match_boost' => ['scoring.title_match_boost', 3.0, 'titleMatchBoost'],
      'title_all_terms_multiplier' => ['scoring.title_all_terms_multiplier', 2.0, 'titleAllTermsMultiplier'],
      'content_match_boost' => ['scoring.content_match_boost', 0.8, 'contentMatchBoost'],
      'recency_boost_max' => ['scoring.recency_boost_max', 0.9, 'recencyBoostMax'],
      'recency_half_life_days' => ['scoring.recency_half_life_days', 30, 'recencyHalfLifeDays'],
      'recency_penalty_after_days' => ['scoring.recency_penalty_after_days', 90, 'recencyPenaltyAfterDays'],
      'recency_max_penalty' => ['scoring.recency_max_penalty', 0.1, 'recencyMaxPenalty'],
      'expand_primary_weight' => ['scoring.expand_primary_weight', 0.5, 'expandPrimaryWeight'],
      // Language and recency strategy (present in scoring config section).
      'language' => ['scoring.language', 'fr', 'language'],
      'recency_strategy' => ['scoring.recency_strategy', 'linear', 'recencyStrategy'],
    ];
  }

  /**
   * Display config overrides affect ScoltaConfig.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('displayOverrideProvider')]
  public function testDisplayConfigOverridesAffectScoltaConfig(
    string $configKey,
    mixed $customValue,
    string $propertyName,
  ): void {
    $defaults = $this->getInstallDefaults();

    $modified = $defaults;
    $this->setNestedValue($modified, $configKey, $customValue);
    $modifiedConfig = $this->simulateGetConfig($modified);

    $this->assertEquals(
      $customValue,
      $modifiedConfig->$propertyName,
      "Changing config '{$configKey}' should change ScoltaConfig::\${$propertyName}"
    );
  }

  public static function displayOverrideProvider(): array {
    return [
      'excerpt_length' => ['display.excerpt_length', 500, 'excerptLength'],
      'results_per_page' => ['display.results_per_page', 25, 'resultsPerPage'],
      'max_pagefind_results' => ['display.max_pagefind_results', 100, 'maxPagefindResults'],
      'ai_summary_top_n' => ['display.ai_summary_top_n', 10, 'aiSummaryTopN'],
      'ai_summary_max_chars' => ['display.ai_summary_max_chars', 5000, 'aiSummaryMaxChars'],
    ];
  }

  // -------------------------------------------------------------------
  // 4. Config changes propagate to JS scoring output.
  // -------------------------------------------------------------------

  /**
   * toJsScoringConfig() output changes when config values change.
   *
   * This verifies the full pipeline: Drupal config → ScoltaConfig → JS.
   */
  public function testJsScoringOutputReflectsConfigChanges(): void {
    $defaults = $this->getInstallDefaults();

    // Default config.
    $defaultConfig = $this->simulateGetConfig($defaults);
    $defaultJs = $defaultConfig->toJsScoringConfig();

    // Modified config: bump title boost and change results per page.
    $modified = $defaults;
    $modified['scoring']['title_match_boost'] = 5.0;
    $modified['display']['results_per_page'] = 42;
    $modified['display']['excerpt_length'] = 999;
    $modified['ai_expand_query'] = false;
    $modified['max_follow_ups'] = 7;

    $modifiedConfig = $this->simulateGetConfig($modified);
    $modifiedJs = $modifiedConfig->toJsScoringConfig();

    // JS output should reflect the changes.
    $this->assertEquals(5.0, $modifiedJs['TITLE_MATCH_BOOST']);
    $this->assertNotEquals($defaultJs['TITLE_MATCH_BOOST'], $modifiedJs['TITLE_MATCH_BOOST']);

    $this->assertEquals(42, $modifiedJs['RESULTS_PER_PAGE']);
    $this->assertEquals(999, $modifiedJs['EXCERPT_LENGTH']);

    $this->assertFalse($modifiedJs['AI_EXPAND_QUERY']);
    $this->assertTrue($defaultJs['AI_EXPAND_QUERY']);

    $this->assertEquals(7, $modifiedJs['AI_MAX_FOLLOWUPS']);
    $this->assertEquals(3, $defaultJs['AI_MAX_FOLLOWUPS']);
  }

  // -------------------------------------------------------------------
  // 5. AI feature toggles flow correctly.
  // -------------------------------------------------------------------

  public function testDisablingAiExpandQueryAffectsConfig(): void {
    $defaults = $this->getInstallDefaults();

    // Default: enabled.
    $defaultConfig = $this->simulateGetConfig($defaults);
    $this->assertTrue($defaultConfig->aiExpandQuery);

    // Disabled.
    $modified = $defaults;
    $modified['ai_expand_query'] = false;
    $modifiedConfig = $this->simulateGetConfig($modified);
    $this->assertFalse($modifiedConfig->aiExpandQuery);
  }

  public function testDisablingAiSummarizeAffectsConfig(): void {
    $defaults = $this->getInstallDefaults();

    $defaultConfig = $this->simulateGetConfig($defaults);
    $this->assertTrue($defaultConfig->aiSummarize);

    $modified = $defaults;
    $modified['ai_summarize'] = false;
    $modifiedConfig = $this->simulateGetConfig($modified);
    $this->assertFalse($modifiedConfig->aiSummarize);
  }

  public function testMaxFollowUpsAffectsConfig(): void {
    $defaults = $this->getInstallDefaults();

    $defaultConfig = $this->simulateGetConfig($defaults);
    $this->assertEquals(3, $defaultConfig->maxFollowUps);

    $modified = $defaults;
    $modified['max_follow_ups'] = 0;
    $modifiedConfig = $this->simulateGetConfig($modified);
    $this->assertEquals(0, $modifiedConfig->maxFollowUps);
  }

  public function testCacheTtlOverride(): void {
    $defaults = $this->getInstallDefaults();

    $defaultConfig = $this->simulateGetConfig($defaults);
    $this->assertEquals(2592000, $defaultConfig->cacheTtl);

    $modified = $defaults;
    $modified['cache_ttl'] = 0;
    $modifiedConfig = $this->simulateGetConfig($modified);
    $this->assertEquals(0, $modifiedConfig->cacheTtl);
  }

  public function testCustomPromptsOverrideDefaults(): void {
    $defaults = $this->getInstallDefaults();

    // Defaults: empty prompts.
    $defaultConfig = $this->simulateGetConfig($defaults);
    $this->assertEmpty($defaultConfig->promptExpandQuery);
    $this->assertEmpty($defaultConfig->promptSummarize);
    $this->assertEmpty($defaultConfig->promptFollowUp);

    // Custom prompts.
    $modified = $defaults;
    $modified['prompt_expand_query'] = 'You are a search assistant for {SITE_NAME}.';
    $modified['prompt_summarize'] = 'Summarize results for {SITE_NAME}.';
    $modified['prompt_follow_up'] = 'Answer follow-ups about {SITE_NAME}.';

    $modifiedConfig = $this->simulateGetConfig($modified);
    $this->assertEquals('You are a search assistant for {SITE_NAME}.', $modifiedConfig->promptExpandQuery);
    $this->assertEquals('Summarize results for {SITE_NAME}.', $modifiedConfig->promptSummarize);
    $this->assertEquals('Answer follow-ups about {SITE_NAME}.', $modifiedConfig->promptFollowUp);
  }

  public function testAiProviderAndModelOverride(): void {
    $defaults = $this->getInstallDefaults();

    $modified = $defaults;
    $modified['ai_provider'] = 'openai';
    $modified['ai_model'] = 'gpt-4o';
    $modified['ai_base_url'] = 'https://proxy.example.com';

    $modifiedConfig = $this->simulateGetConfig($modified);
    $clientConfig = $modifiedConfig->toAiClientConfig();

    $this->assertEquals('openai', $clientConfig['provider']);
    $this->assertEquals('gpt-4o', $clientConfig['model']);
    $this->assertEquals('https://proxy.example.com', $clientConfig['base_url']);
  }

  public function testSiteNameAndDescriptionAffectConfig(): void {
    $defaults = $this->getInstallDefaults();

    $modified = $defaults;
    $modified['site_name'] = 'Acme Corp';
    $modified['site_description'] = 'corporate intranet';

    $modifiedConfig = $this->simulateGetConfig($modified);
    $this->assertEquals('Acme Corp', $modifiedConfig->siteName);
    $this->assertEquals('corporate intranet', $modifiedConfig->siteDescription);
  }

  /**
   * AI languages config propagates to ScoltaConfig and toJsScoringConfig output.
   */
  public function testAiLanguagesPropagateToJsScoringConfig(): void {
    $defaults = $this->getInstallDefaults();

    $modified = $defaults;
    $modified['ai_languages'] = ['en', 'fr', 'de'];

    $config = $this->simulateGetConfig($modified);

    $this->assertEquals(['en', 'fr', 'de'], $config->aiLanguages);
    $js = $config->toJsScoringConfig();
    $this->assertEquals(['en', 'fr', 'de'], $js['AI_LANGUAGES']);
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  private function getInstallDefaults(): array {
    return Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
  }

  /**
   * Simulate ScoltaAiService::getConfig() — flatten nested Drupal config.
   */
  private function simulateGetConfig(array $drupalConfig, string $apiKey = 'test-key'): ScoltaConfig {
    $values = $drupalConfig;

    if (isset($values['scoring']) && is_array($values['scoring'])) {
      foreach ($values['scoring'] as $key => $value) {
        $values[$key] = $value;
      }
      unset($values['scoring']);
    }

    if (isset($values['display']) && is_array($values['display'])) {
      foreach ($values['display'] as $key => $value) {
        $values[$key] = $value;
      }
      unset($values['display']);
    }

    unset($values['pagefind']);
    $values['ai_api_key'] = $apiKey;

    return ScoltaConfig::fromArray($values);
  }

  /**
   * Flatten nested array keys to dot notation.
   */
  private function flattenKeys(array $array, string $prefix = ''): array {
    $keys = [];
    foreach ($array as $key => $value) {
      $fullKey = $prefix ? "{$prefix}.{$key}" : $key;
      if (is_array($value) && !array_is_list($value)) {
        $keys = array_merge($keys, $this->flattenKeys($value, $fullKey));
      } else {
        $keys[] = $fullKey;
      }
    }
    return $keys;
  }

  /**
   * Set a dot-notation key in a nested array.
   */
  private function setNestedValue(array &$array, string $dotKey, mixed $value): void {
    $parts = explode('.', $dotKey);
    $current = &$array;
    foreach ($parts as $part) {
      if (!isset($current[$part])) {
        $current[$part] = [];
      }
      $current = &$current[$part];
    }
    $current = $value;
  }

}
