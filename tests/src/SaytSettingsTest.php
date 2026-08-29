<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the search-as-you-type settings surface in the shipped config files.
 *
 * SAYT itself is implemented entirely in the vendored browser bundle. What
 * this module owns is the settings surface around it, starting with the
 * config layer: install defaults, config schema, and the documented example.
 * A key missing from any one of those is invisible in the others — the bundle
 * falls back to its own hardcoded default, so the feature keeps working while
 * the setting silently does nothing.
 *
 * These parse the shipped YAML, so they need no Drupal bootstrap. The
 * behavior (form, drupalSettings bridge, update hook) is executed in
 * tests/src/Functional/SaytSettingsFunctionalTest.php.
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

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

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

}
