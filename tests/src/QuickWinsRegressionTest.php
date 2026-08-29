<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression tests for the quality quick-wins audit fixes.
 *
 * Structural (YAML/reflection) tests in the established no-bootstrap style.
 * Each section pins one audit finding so the defect cannot silently return.
 */
class QuickWinsRegressionTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // hook_requirements() must read the real config key (pagefind.binary).
  // -------------------------------------------------------------------

  public function testRequirementsReadsExistingConfigKey(): void {
    // The key hook_requirements() reads must actually ship in install config.
    $installConfig = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $this->assertArrayHasKey('binary', $installConfig['pagefind']);
  }

  // -------------------------------------------------------------------
  // Dismiss route: CSRF protection + open-redirect hardening.
  // -------------------------------------------------------------------

  public function testDismissRouteRequiresCsrfToken(): void {
    $routing = Yaml::parseFile($this->moduleRoot . '/scolta.routing.yml');
    $this->assertSame(
      'TRUE',
      $routing['scolta.dismiss_rebuild_notice']['requirements']['_csrf_token'] ?? NULL,
      'The state-changing dismiss GET route must require a CSRF token'
    );
  }

  // -------------------------------------------------------------------
  // Config schema: wildcard '*' mapping keys are not a supported schema
  // construct — arbitrary-key maps must be sequences.
  // -------------------------------------------------------------------

  public function testSchemaHasNoWildcardMappingKeys(): void {
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');
    $wildcards = [];
    $walk = function (array $node, string $path) use (&$walk, &$wildcards): void {
      foreach ($node as $key => $value) {
        if ($key === 'mapping' && is_array($value) && array_key_exists('*', $value)) {
          $wildcards[] = $path;
        }
        if (is_array($value)) {
          $walk($value, $path . '.' . $key);
        }
      }
    };
    $walk($schema, 'schema');
    $this->assertSame([], $wildcards,
      "mapping: {'*': ...} is unsupported in config schema; use type: sequence");
  }

  public function testArbitraryKeyMapsAreSequences(): void {
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');
    $settings = $schema['scolta.settings']['mapping'];
    $this->assertSame('sequence', $settings['sortable_field_descriptions']['type']);
    $this->assertSame('sequence', $settings['filter_field_descriptions']['type']);
    $this->assertSame('sequence', $settings['field_mappings']['mapping']['sortable']['type']);
    $this->assertSame('sequence', $settings['field_mappings']['mapping']['filters']['type']);
  }

  public function testBackendSchemaCoversAutoRebuildDelay(): void {
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');
    $backend = $schema['search_api.backend.plugin.scolta_pagefind']['mapping'];
    $this->assertSame('integer', $backend['auto_rebuild_delay']['type'] ?? NULL,
      'auto_rebuild_delay is saved by ScoltaBackend::submitConfigurationForm() and must have schema');
  }

  public function testAiServiceDefinitionInjectsOptionalAiProvider(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml');
    $args = $services['services']['scolta.ai_service']['arguments'];
    $this->assertContains('@?ai.provider', $args,
      'The ai.provider plugin manager must be optionally injected');
    $this->assertContains('@scolta.amazee_config_storage', $args);
  }

  // -------------------------------------------------------------------
  // Dead code stays dead.
  // -------------------------------------------------------------------

  public function testDeadBatchProcessChunkIsRemoved(): void {
    $this->assertFalse(
      (new \ReflectionClass(\Drupal\scolta\Batch\ScoltaBatchOperations::class))->hasMethod('processChunk'),
      'processChunk() had no callers (loadAndProcessChunk replaced it)'
    );
  }

  public function testDeadMemoryBudgetHelpersAreRemoved(): void {
    $reflection = new \ReflectionClass(\Drupal\scolta\Form\MemoryBudgetSettingsFieldSet::class);
    $this->assertFalse($reflection->hasMethod('extract'));
    $this->assertFalse($reflection->hasMethod('formatBytes'));
  }

  public function testAmazeeFormDropsUnusedClientProperty(): void {
    $this->assertFalse(
      (new \ReflectionClass(\Drupal\scolta\Form\AmazeeSettingsForm::class))->hasProperty('amazeeClient'),
      'The constructor-injected AmazeeClient was never read'
    );
  }

}
