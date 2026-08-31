<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Config schema stays in sync with install defaults.
 *
 * A config key with no schema entry (or vice versa) does not fail a build —
 * it only logs a Drupal config-schema notice — so nothing else in the suite
 * catches this drift.
 */
class YamlIntegrityTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  public function testInstallConfigKeysMatchSchema(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');

    $this->assertArrayHasKey('scolta.settings', $schema);
    $schemaMapping = $schema['scolta.settings']['mapping'];

    // Every top-level key in install config must exist in schema.
    foreach (array_keys($install) as $key) {
      $this->assertArrayHasKey(
        $key, $schemaMapping,
        "Install config key '{$key}' is missing from schema"
      );
    }

    // Every top-level key in schema must exist in install config.
    foreach (array_keys($schemaMapping) as $key) {
      $this->assertArrayHasKey(
        $key, $install,
        "Schema key '{$key}' has no default in install config"
      );
    }
  }

  public function testScoringSubkeysMatchSchema(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');

    $installScoring = $install['scoring'] ?? [];
    $schemaScoring = $schema['scolta.settings']['mapping']['scoring']['mapping'] ?? [];

    foreach (array_keys($installScoring) as $key) {
      $this->assertArrayHasKey($key, $schemaScoring,
        "Install scoring.{$key} missing from schema");
    }
    foreach (array_keys($schemaScoring) as $key) {
      $this->assertArrayHasKey($key, $installScoring,
        "Schema scoring.{$key} missing from install config");
    }
  }

  public function testDisplaySubkeysMatchSchema(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');

    $installDisplay = $install['display'] ?? [];
    $schemaDisplay = $schema['scolta.settings']['mapping']['display']['mapping'] ?? [];

    foreach (array_keys($installDisplay) as $key) {
      $this->assertArrayHasKey($key, $schemaDisplay,
        "Install display.{$key} missing from schema");
    }
    foreach (array_keys($schemaDisplay) as $key) {
      $this->assertArrayHasKey($key, $installDisplay,
        "Schema display.{$key} missing from install config");
    }
  }

  public function testPagefindSubkeysMatchSchema(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');

    $installPagefind = $install['pagefind'] ?? [];
    $schemaPagefind = $schema['scolta.settings']['mapping']['pagefind']['mapping'] ?? [];

    foreach (array_keys($installPagefind) as $key) {
      $this->assertArrayHasKey($key, $schemaPagefind,
        "Install pagefind.{$key} missing from schema");
    }
    foreach (array_keys($schemaPagefind) as $key) {
      $this->assertArrayHasKey($key, $installPagefind,
        "Schema pagefind.{$key} missing from install config");
    }
  }

  public function testInstallConfigValueTypesMatchSchema(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');
    $mapping = $schema['scolta.settings']['mapping'];

    $typeChecks = [
      'string' => 'is_string',
      'integer' => 'is_int',
      'boolean' => 'is_bool',
      'float' => fn($v) => is_float($v) || is_int($v),
      'mapping' => 'is_array',
    ];

    foreach ($mapping as $key => $schemaDef) {
      $type = $schemaDef['type'] ?? null;
      if ($type && isset($typeChecks[$type]) && array_key_exists($key, $install)) {
        $check = $typeChecks[$type];
        $this->assertTrue(
          $check($install[$key]),
          "Install config '{$key}' should be type '{$type}', got " . gettype($install[$key])
        );
      }
    }
  }

}
