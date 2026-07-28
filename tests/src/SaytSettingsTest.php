<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the search-as-you-type settings surface across every layer it touches.
 *
 * SAYT itself is implemented entirely in the vendored browser bundle. What this
 * module owns is the settings surface around it: install defaults, config
 * schema, the admin form, the drupalSettings bridge in the search block, and
 * the update hook that gives an existing site the same defaults a fresh install
 * gets. A key missing from any one of those layers is invisible in the others —
 * the bundle falls back to its own hardcoded default, so the feature keeps
 * working while the setting silently does nothing.
 *
 * These are file-inspection tests, so they need no Drupal bootstrap. The
 * behavior is executed in tests/src/Functional/SaytSettingsFunctionalTest.php.
 */
class SaytSettingsTest extends TestCase {

  /**
   * The ten config keys and the defaults they must carry.
   *
   * Byte-equal to the defaults the browser bundle falls back to, and to the
   * table in scolta-php's docs/CONFIG_REFERENCE.md.
   */
  private const DEFAULTS = [
    'sayt_enabled' => TRUE,
    'sayt_min_chars' => 2,
    'sayt_debounce_ms' => 150,
    'sayt_max_suggestions' => 6,
    'sayt_recent_searches' => TRUE,
    'sayt_max_recent' => 3,
    'sayt_expand' => TRUE,
    'sayt_expand_per_minute' => 6,
    'sayt_expansion_delay_ms' => 500,
    'sayt_suggestion_action' => 'navigate',
  ];

  /**
   * The config key to browser key mapping the bundle reads.
   */
  private const BROWSER_KEYS = [
    'sayt_enabled' => 'saytEnabled',
    'sayt_min_chars' => 'saytMinChars',
    'sayt_debounce_ms' => 'saytDebounceMs',
    'sayt_max_suggestions' => 'saytMaxSuggestions',
    'sayt_recent_searches' => 'saytRecentSearches',
    'sayt_max_recent' => 'saytMaxRecent',
    'sayt_expand' => 'saytExpand',
    'sayt_expand_per_minute' => 'saytExpandPerMinute',
    'sayt_expansion_delay_ms' => 'saytExpansionDelayMs',
    'sayt_suggestion_action' => 'saytSuggestionAction',
  ];

  private string $moduleRoot;

  private string $installSource;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->installSource = file_get_contents($this->moduleRoot . '/scolta.install');
  }

  // -------------------------------------------------------------------
  // Install defaults and schema.
  // -------------------------------------------------------------------

  public function testInstallConfigCarriesEveryDefault(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');

    foreach (self::DEFAULTS as $key => $value) {
      $this->assertArrayHasKey(
        $key, $install,
        "config/install/scolta.settings.yml must ship a default for {$key}"
      );
      $this->assertSame(
        $value, $install[$key],
        "The install default for {$key} must match the browser bundle's own fallback"
      );
    }
  }

  public function testSchemaTypesMatchTheDefaults(): void {
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');
    $mapping = $schema['scolta.settings']['mapping'];

    $expectedTypes = [
      'boolean' => 'boolean',
      'integer' => 'integer',
      'string' => 'string',
    ];

    foreach (self::DEFAULTS as $key => $value) {
      $this->assertArrayHasKey($key, $mapping, "Schema must declare {$key}");
      $this->assertSame(
        $expectedTypes[gettype($value)],
        $mapping[$key]['type'],
        "Schema type for {$key} does not match the type of its default"
      );
      $this->assertNotEmpty($mapping[$key]['label'] ?? '', "Schema entry {$key} needs a label");
    }
  }

  public function testExampleConfigDocumentsEverySetting(): void {
    $example = Yaml::parseFile($this->moduleRoot . '/config/scolta.settings.example.yml');

    foreach (self::DEFAULTS as $key => $value) {
      $this->assertArrayHasKey(
        $key, $example,
        "config/scolta.settings.example.yml must document {$key}"
      );
      $this->assertSame(
        $value, $example[$key],
        "The example value for {$key} must be the shipped default"
      );
    }
  }

  // -------------------------------------------------------------------
  // The settings form exposes each key as a first-class field.
  // -------------------------------------------------------------------

  public function testFormRendersASaytSection(): void {
    $form = file_get_contents($this->moduleRoot . '/src/Form/ScoltaSettingsForm.php');

    $this->assertStringContainsString(
      "\$form['sayt'] = [",
      $form,
      'The settings form must carry a dedicated search-as-you-type section'
    );
    $this->assertStringContainsString(
      "\$this->t('Search as you type')",
      $form,
      'The section needs the title an admin searches the page for'
    );
  }

  public function testFormExposesEverySetting(): void {
    $form = file_get_contents($this->moduleRoot . '/src/Form/ScoltaSettingsForm.php');

    foreach (array_keys(self::DEFAULTS) as $key) {
      $this->assertStringContainsString(
        "\$form['sayt']['{$key}']",
        $form,
        "{$key} must be a form field, not a config-only setting"
      );
      $this->assertStringContainsString(
        "->set('{$key}',",
        $form,
        "submitForm() must persist {$key} — a field that is never saved is worse than no field"
      );
    }
  }

  public function testNumericFieldsAreBounded(): void {
    $form = file_get_contents($this->moduleRoot . '/src/Form/ScoltaSettingsForm.php');

    // Each numeric field's element definition, from its key to the closing
    // bracket of the array literal.
    foreach (['sayt_min_chars', 'sayt_debounce_ms', 'sayt_max_suggestions', 'sayt_max_recent', 'sayt_expand_per_minute', 'sayt_expansion_delay_ms'] as $key) {
      $start = strpos($form, "\$form['sayt']['{$key}']");
      $this->assertNotFalse($start, "{$key} must be a form field");
      $element = substr($form, $start, (int) strpos($form, '];', $start) - $start);

      $this->assertStringContainsString("'#min'", $element, "{$key} must declare a lower bound");
      $this->assertStringContainsString("'#max'", $element, "{$key} must declare an upper bound");
    }
  }

  public function testEnrichmentCapExplainsTheSharedBudget(): void {
    $form = file_get_contents($this->moduleRoot . '/src/Form/ScoltaSettingsForm.php');
    $start = strpos($form, "\$form['sayt']['sayt_expand_per_minute']");
    $this->assertNotFalse($start, 'sayt_expand_per_minute must be a form field');
    $element = substr($form, $start, (int) strpos($form, '];', $start) - $start);

    $this->assertStringContainsString(
      'flood budget',
      $element,
      'The cap exists because SAYT expansions share the AI flood budget with committed searches; '
      . 'an admin who is not told that reads the number as an arbitrary throttle and raises it'
    );
  }

  public function testSuggestionActionOffersBothActions(): void {
    $form = file_get_contents($this->moduleRoot . '/src/Form/ScoltaSettingsForm.php');
    $start = strpos($form, "\$form['sayt']['sayt_suggestion_action']");
    $this->assertNotFalse($start, 'sayt_suggestion_action must be a form field');
    $element = substr($form, $start, (int) strpos($form, '];', $start) - $start);

    $this->assertStringContainsString("'navigate' =>", $element, 'The navigate action must be selectable');
    $this->assertStringContainsString("'search' =>", $element, 'The search action must be selectable');
    $this->assertStringContainsString(
      'recent-search suggestion always runs the search',
      $element,
      'The description must say that a recent search ignores this setting; otherwise the behavior reads as a bug'
    );
  }

  // -------------------------------------------------------------------
  // The block bridges every key into drupalSettings.
  // -------------------------------------------------------------------

  public function testBlockEmitsEveryBrowserKey(): void {
    $block = file_get_contents($this->moduleRoot . '/src/Plugin/Block/ScoltaSearchBlock.php');

    foreach (self::BROWSER_KEYS as $configKey => $browserKey) {
      $this->assertStringContainsString(
        "'{$browserKey}' =>",
        $block,
        "ScoltaSearchBlock::build() must emit {$browserKey}; the bundle reads it off the instance "
        . 'config and takes its own hardcoded default when it is absent, so a missing key looks '
        . 'exactly like a working one until the setting is changed'
      );
      $this->assertStringContainsString(
        "'{$configKey}'",
        $block,
        "The emitted {$browserKey} must come from the {$configKey} config key"
      );
    }
  }

  public function testBundleReadsEveryKeyTheBlockEmits(): void {
    $bundle = file_get_contents($this->moduleRoot . '/js/scolta.js');
    $this->assertNotFalse($bundle, 'Unable to read the vendored js/scolta.js');

    foreach (self::BROWSER_KEYS as $browserKey) {
      $this->assertStringContainsString(
        "instanceConfig.{$browserKey}",
        $bundle,
        "The vendored bundle does not read {$browserKey} — either the re-vendor is stale or the "
        . 'key was renamed upstream'
      );
    }
  }

  // -------------------------------------------------------------------
  // The update hook backfills existing sites.
  // -------------------------------------------------------------------

  public function testUpdateHookExists(): void {
    $this->assertMatchesRegularExpression(
      '/function scolta_update_10002\(/',
      $this->installSource,
      'scolta.install must define scolta_update_10002() so existing sites get the SAYT defaults'
    );
  }

  public function testUpdateHookCoversEverySetting(): void {
    $body = $this->functionBody('scolta_update_10002');

    foreach (self::DEFAULTS as $key => $value) {
      $this->assertStringContainsString("'{$key}'", $body, "The update hook must cover {$key}");
    }

    // Spot-check the non-boolean defaults, which a copy-paste would get wrong.
    $this->assertStringContainsString("'sayt_min_chars' => 2", $body);
    $this->assertStringContainsString("'sayt_debounce_ms' => 150", $body);
    $this->assertStringContainsString("'sayt_max_suggestions' => 6", $body);
    $this->assertStringContainsString("'sayt_max_recent' => 3", $body);
    $this->assertStringContainsString("'sayt_expand_per_minute' => 6", $body);
    $this->assertStringContainsString("'sayt_expansion_delay_ms' => 500", $body);
    $this->assertStringContainsString("'sayt_suggestion_action' => 'navigate'", $body);
  }

  public function testUpdateHookOnlyWritesAbsentKeys(): void {
    $body = $this->functionBody('scolta_update_10002');

    $this->assertStringContainsString(
      '=== NULL',
      $body,
      'The hook must test for an absent key. A falsy test (empty(), !$value) would overwrite a '
      . 'site that deliberately set sayt_enabled to FALSE, turning the feature back on under them'
    );
  }

  public function testUpdateHookReturnsASummary(): void {
    $body = $this->functionBody('scolta_update_10002');

    $this->assertMatchesRegularExpression(
      '/return t\(/',
      $body,
      'An update hook returns a translated summary string for drush updatedb and update.php'
    );
  }

  /**
   * Extracts a top-level function body from the install file source.
   *
   * @param string $name
   *   The function name.
   *
   * @return string
   *   Everything between the function's opening and closing brace.
   */
  private function functionBody(string $name): string {
    $start = strpos($this->installSource, "function {$name}(");
    $this->assertNotFalse($start, "scolta.install must define {$name}()");

    $open = strpos($this->installSource, '{', $start);
    $this->assertNotFalse($open, "{$name}() must have a body");

    $depth = 0;
    $length = strlen($this->installSource);
    for ($i = $open; $i < $length; $i++) {
      $char = $this->installSource[$i];
      if ($char === '{') {
        $depth++;
      }
      elseif ($char === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($this->installSource, $open, $i - $open + 1);
        }
      }
    }

    $this->fail("Unbalanced braces while reading {$name}()");
  }

}
