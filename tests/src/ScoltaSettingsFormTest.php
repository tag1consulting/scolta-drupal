<?php

declare(strict_types=1);

// The unit-test environment runs without drupal/core (CI "provides" it), so
// the form's base class and the interfaces validateForm() touches are stubbed
// when absent — the same pattern ScoltaRebuildWorkerTest uses. Locally (and in
// the phpstan job) the real core classes exist and the stubs are skipped.
// phpcs:disable
namespace Drupal\Core\Form {
    if (!class_exists(ConfigFormBase::class)) {
        abstract class ConfigFormBase {
            protected $stringTranslation;

            public function setStringTranslation($translation) {
                $this->stringTranslation = $translation;
                return $this;
            }

            protected function t($string, array $args = [], array $options = []) {
                return $string;
            }

            public function validateForm(array &$form, FormStateInterface $form_state) {}
        }
    }
    if (!interface_exists(FormStateInterface::class)) {
        interface FormStateInterface {
            public function getValue($key, $default = NULL);
            public function get($property);
            public function setErrorByName($name, $message = '');
        }
    }
}

namespace Drupal\Core\StringTranslation {
    if (!interface_exists(TranslationInterface::class)) {
        interface TranslationInterface {}
    }
}
// phpcs:enable

namespace Drupal\scolta\Tests {

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\scolta\Form\ScoltaSettingsForm;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Validates the ScoltaSettingsForm and the full config pipeline.
 *
 * Tests verify that:
 * 1. Every config key actually reaches ScoltaConfig and affects behavior.
 * 2. The JS scoring output changes when config values change.
 * 3. AI feature toggles flow through to ScoltaConfig correctly.
 * 4. validateForm() accepts and rejects AI base URLs behaviorally.
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
    $params = (new \ReflectionMethod(ScoltaSettingsForm::class, '__construct'))->getParameters();
    $types = array_map(
      static fn (\ReflectionParameter $p): string => $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : '',
      $params,
    );

    $this->assertContains(
      'Drupal\Core\Config\TypedConfigManagerInterface',
      $types,
      'Constructor must accept a TypedConfigManagerInterface parameter for Drupal 11 compatibility'
    );
    $this->assertSame(
      'Drupal\Core\Config\TypedConfigManagerInterface',
      $types[1],
      'The typed config manager must be the second constructor parameter, matching ConfigFormBase'
    );
  }

  // -------------------------------------------------------------------
  // 2. Config values reach ScoltaConfig and affect its properties.
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

    $displayValue = is_array($customValue) ? json_encode($customValue) : (string) $customValue;
    $this->assertNotEquals(
      $defaultValue,
      $modifiedConfig->$propertyName,
      "Changing config '{$configKey}' to {$displayValue} should change ScoltaConfig::\${$propertyName}"
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
      'expand_primary_weight' => ['scoring.expand_primary_weight', 0.8, 'expandPrimaryWeight'],
      // Language and recency strategy (present in scoring config section).
      'language' => ['scoring.language', 'fr', 'language'],
      'recency_strategy' => ['scoring.recency_strategy', 'linear', 'recencyStrategy'],
      // Custom stop words.
      'custom_stop_words' => ['scoring.custom_stop_words', ['the', 'a', 'an'], 'customStopWords'],
      // Phrase proximity (not in Drupal admin UI yet, but flow through ScoltaConfig).
      'phrase_adjacent_multiplier' => ['scoring.phrase_adjacent_multiplier', 3.0, 'phraseAdjacentMultiplier'],
      'phrase_near_multiplier' => ['scoring.phrase_near_multiplier', 2.0, 'phraseNearMultiplier'],
      'phrase_near_window' => ['scoring.phrase_near_window', 8, 'phraseNearWindow'],
      'phrase_window' => ['scoring.phrase_window', 20, 'phraseWindow'],
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
  // 3. Config changes propagate to JS scoring output.
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
  // 4. AI feature toggles flow correctly.
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
  // validateForm — AI Base URL validation (issue #86).
  // -------------------------------------------------------------------

  /**
   * validateForm() flags invalid AI base URLs and accepts valid or empty ones.
   *
   * Runs the real validateForm() against a stub form state: getValue() feeds
   * the candidate URL and setErrorByName() records what the form flags. The
   * form object is built without its constructor — validateForm() touches no
   * injected service, only the form state and string translation.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('urlValidationProvider')]
  public function testValidateFormBaseUrlValidation(string $url, bool $shouldBeValid): void {
    /** @var \Drupal\scolta\Form\ScoltaSettingsForm $formObject */
    $formObject = (new \ReflectionClass(ScoltaSettingsForm::class))->newInstanceWithoutConstructor();
    $formObject->setStringTranslation($this->createStub(TranslationInterface::class));

    $errors = [];
    $formState = $this->createStub(FormStateInterface::class);
    // ai_base_url carries the candidate; every other validated field (the
    // recency curve, the pipe-separated mappings) reads as empty so only the
    // URL rule can fire.
    $formState->method('getValue')->willReturnCallback(
      static fn ($key, $default = NULL) => $key === 'ai_base_url' ? $url : ''
    );
    $formState->method('setErrorByName')->willReturnCallback(
      function ($name, $message = '') use (&$errors, $formState) {
        $errors[] = $name;
        return $formState;
      }
    );

    $form = [];
    $formObject->validateForm($form, $formState);

    if ($shouldBeValid) {
      $this->assertSame([], $errors, "URL '{$url}' must be accepted without a form error");
    }
    else {
      $this->assertSame(['ai_base_url'], $errors, "URL '{$url}' must set an error on ai_base_url");
    }
  }

  /**
   * @return array<string, array{string, bool}>
   */
  public static function urlValidationProvider(): array {
    return [
      'empty string'           => ['', TRUE],
      'valid https'            => ['https://api.example.com', TRUE],
      'valid http'             => ['http://api.example.com', TRUE],
      'https with path'        => ['https://api.example.com/v1', TRUE],
      'https with port'        => ['https://api.example.com:8080', TRUE],
      'gibberish'              => ['not-a-url', FALSE],
      'missing scheme'         => ['api.example.com', FALSE],
      'ftp scheme'             => ['ftp://api.example.com', FALSE],
      'incomplete url'         => ['https://', FALSE],
      'just text'              => ['hello world', FALSE],
      'scheme only'            => ['https:', FALSE],
    ];
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

  // -------------------------------------------------------------------
  // PR fix/prompt-drift-cross-adapter-tests — delegation to DefaultPrompts
  // -------------------------------------------------------------------

  /**
   * The resolved default for each prompt key matches what DefaultPrompts
   * produces, so Drupal and WordPress are guaranteed to show identical text.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('allPromptNamesProvider')]
  public function testDefaultPromptMatchesDefaultPromptsResolve(string $name): void {
    $template = \Tag1\Scolta\Prompt\DefaultPrompts::getTemplate($name);
    $resolved = \Tag1\Scolta\Prompt\DefaultPrompts::resolve($name, 'Acme', 'tech company');

    $this->assertNotEmpty($template, "Template '{$name}' must not be empty");
    $this->assertStringContainsString('Acme', $resolved, "Resolved '{$name}' must contain the site name");
  }

  /**
   * @return array<string, array{string}>
   */
  public static function allPromptNamesProvider(): array {
    return [
      'expand_query' => [\Tag1\Scolta\Prompt\DefaultPrompts::EXPAND_QUERY],
      'summarize'    => [\Tag1\Scolta\Prompt\DefaultPrompts::SUMMARIZE],
      'follow_up'    => [\Tag1\Scolta\Prompt\DefaultPrompts::FOLLOW_UP],
    ];
  }

  // -------------------------------------------------------------------
  // show_attribution — issue scolta-php#102.
  // -------------------------------------------------------------------

  /**
   * The install default for show_attribution must be false.
   */
  public function testShowAttributionDefaultIsFalse(): void {
    $defaults = $this->getInstallDefaults();
    $this->assertArrayHasKey(
      'show_attribution',
      $defaults,
      'show_attribution must be present in config/install/scolta.settings.yml'
    );
    $this->assertFalse(
      $defaults['show_attribution'],
      'show_attribution must default to false'
    );
  }

  /**
   * When show_attribution is false, ScoltaConfig::$showAttribution is false.
   */
  public function testShowAttributionFalseFlowsToScoltaConfig(): void {
    $defaults = $this->getInstallDefaults();
    $config = $this->simulateGetConfig($defaults);
    $this->assertFalse(
      $config->showAttribution,
      'ScoltaConfig::$showAttribution must be false when show_attribution is false in Drupal config'
    );
  }

  /**
   * When show_attribution is true, ScoltaConfig::$showAttribution is true.
   */
  public function testShowAttributionTrueFlowsToScoltaConfig(): void {
    $defaults = $this->getInstallDefaults();
    $modified = $defaults;
    $modified['show_attribution'] = true;
    $config = $this->simulateGetConfig($modified);
    $this->assertTrue(
      $config->showAttribution,
      'ScoltaConfig::$showAttribution must be true when show_attribution is true in Drupal config'
    );
  }

  // -------------------------------------------------------------------
  // facet_mode — when the browser loads the facet index.
  // -------------------------------------------------------------------

  /**
   * The install default must be 'eager' — the behavior every site already has.
   */
  public function testFacetModeInstallDefaultIsEager(): void {
    $defaults = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');

    $this->assertArrayHasKey(
      'facet_mode',
      $defaults,
      'facet_mode must be present in config/install/scolta.settings.yml'
    );
    $this->assertSame(
      'eager',
      $defaults['facet_mode'],
      'facet_mode must default to eager so existing sites are unaffected'
    );
  }

  // -------------------------------------------------------------------
  // Field mapping install defaults.
  // -------------------------------------------------------------------

  public function testFieldMappingsInInstallDefaults(): void {
    $defaults = $this->getInstallDefaults();
    $this->assertArrayHasKey('field_mappings', $defaults, 'Install defaults must include field_mappings');
    $this->assertArrayHasKey('sortable', $defaults['field_mappings'], 'field_mappings must have sortable key');
    $this->assertArrayHasKey('filters', $defaults['field_mappings'], 'field_mappings must have filters key');
    $this->assertEmpty($defaults['field_mappings']['sortable'], 'Default field_mappings.sortable must be empty');
    $this->assertEmpty($defaults['field_mappings']['filters'], 'Default field_mappings.filters must be empty');
  }

}

}
