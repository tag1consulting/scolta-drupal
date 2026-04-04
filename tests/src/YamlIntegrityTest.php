<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates all YAML config files parse correctly and are internally consistent.
 */
class YamlIntegrityTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // Basic YAML parsing — every .yml file must parse without error.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('ymlFileProvider')]
  public function testYamlFilesAreValid(string $file): void {
    $content = file_get_contents($file);
    $this->assertNotFalse($content, "Could not read {$file}");

    $parsed = Yaml::parse($content);
    // NULL is valid (empty file), but a parse error would throw.
    $this->assertTrue(
      $parsed === null || is_array($parsed),
      "YAML file did not parse to array or null: {$file}"
    );
  }

  public static function ymlFileProvider(): \Generator {
    $root = dirname(__DIR__, 2);
    $files = glob($root . '/*.yml') ?: [];
    $files = array_merge($files, glob($root . '/config/**/*.yml') ?: []);
    foreach ($files as $file) {
      yield basename($file) => [$file];
    }
  }

  // -------------------------------------------------------------------
  // Config schema vs install defaults alignment.
  // -------------------------------------------------------------------

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

  // -------------------------------------------------------------------
  // Schema type correctness for install defaults.
  // -------------------------------------------------------------------

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

  // -------------------------------------------------------------------
  // services.yml structure.
  // -------------------------------------------------------------------

  public function testServicesYamlStructure(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml');
    $this->assertArrayHasKey('services', $services);

    $expected = [
      'logger.channel.scolta',
      'scolta.ai_service',
      'scolta.pagefind_exporter',
      'scolta.pagefind_builder',
    ];

    foreach ($expected as $serviceId) {
      $this->assertArrayHasKey($serviceId, $services['services'],
        "Missing service: {$serviceId}");
    }
  }

  public function testDrushServicesYamlStructure(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $this->assertArrayHasKey('services', $drush);
    $this->assertArrayHasKey('scolta.commands', $drush['services']);
    $this->assertEquals(
      'Drupal\scolta\Commands\ScoltaCommands',
      $drush['services']['scolta.commands']['class']
    );
  }

  // -------------------------------------------------------------------
  // routing.yml structure.
  // -------------------------------------------------------------------

  public function testRoutingYamlStructure(): void {
    $routing = Yaml::parseFile($this->moduleRoot . '/scolta.routing.yml');

    $expectedRoutes = [
      'scolta.settings' => '/admin/config/search/scolta',
      'scolta.expand' => '/api/scolta/v1/expand-query',
      'scolta.summarize' => '/api/scolta/v1/summarize',
      'scolta.followup' => '/api/scolta/v1/followup',
    ];

    foreach ($expectedRoutes as $name => $path) {
      $this->assertArrayHasKey($name, $routing, "Missing route: {$name}");
      $this->assertEquals($path, $routing[$name]['path'],
        "Route {$name} has wrong path");
    }
  }

  public function testApiRoutesRequirePostMethod(): void {
    $routing = Yaml::parseFile($this->moduleRoot . '/scolta.routing.yml');

    $apiRoutes = ['scolta.expand', 'scolta.summarize', 'scolta.followup'];
    foreach ($apiRoutes as $route) {
      $this->assertContains('POST', $routing[$route]['methods'] ?? [],
        "Route {$route} should require POST");
    }
  }

  public function testApiRoutesRequireCorrectPermission(): void {
    $routing = Yaml::parseFile($this->moduleRoot . '/scolta.routing.yml');
    $permissions = Yaml::parseFile($this->moduleRoot . '/scolta.permissions.yml');

    // Admin route requires 'administer scolta'.
    $this->assertEquals(
      'administer scolta',
      $routing['scolta.settings']['requirements']['_permission']
    );
    $this->assertArrayHasKey('administer scolta', $permissions);

    // API routes require 'use scolta ai'.
    foreach (['scolta.expand', 'scolta.summarize', 'scolta.followup'] as $route) {
      $this->assertEquals(
        'use scolta ai',
        $routing[$route]['requirements']['_permission'],
        "Route {$route} should require 'use scolta ai'"
      );
    }
    $this->assertArrayHasKey('use scolta ai', $permissions);
  }

  // -------------------------------------------------------------------
  // libraries.yml.
  // -------------------------------------------------------------------

  public function testLibrariesYamlStructure(): void {
    $libs = Yaml::parseFile($this->moduleRoot . '/scolta.libraries.yml');

    $this->assertArrayHasKey('search', $libs);
    $this->assertArrayHasKey('drupal_bridge', $libs);

    // drupal_bridge depends on search.
    $this->assertContains(
      'scolta/search',
      $libs['drupal_bridge']['dependencies']
    );
  }

  public function testBridgeJsFileExists(): void {
    $this->assertFileExists(
      $this->moduleRoot . '/js/scolta-drupal-bridge.js',
      'drupal_bridge JS file must exist'
    );
  }

  // -------------------------------------------------------------------
  // info.yml.
  // -------------------------------------------------------------------

  public function testInfoYamlStructure(): void {
    $info = Yaml::parseFile($this->moduleRoot . '/scolta.info.yml');

    $this->assertEquals('Scolta', $info['name']);
    $this->assertEquals('module', $info['type']);
    $this->assertArrayHasKey('core_version_requirement', $info);
    $this->assertContains('search_api:search_api', $info['dependencies']);
  }

  // -------------------------------------------------------------------
  // Search API backend schema.
  // -------------------------------------------------------------------

  public function testSearchApiBackendSchemaExists(): void {
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');
    $this->assertArrayHasKey('search_api.backend.plugin.scolta_pagefind', $schema);

    $backendMapping = $schema['search_api.backend.plugin.scolta_pagefind']['mapping'];
    $expectedKeys = ['build_dir', 'output_dir', 'pagefind_binary', 'auto_rebuild', 'view_mode'];
    foreach ($expectedKeys as $key) {
      $this->assertArrayHasKey($key, $backendMapping,
        "Backend schema missing key: {$key}");
    }
  }

}
