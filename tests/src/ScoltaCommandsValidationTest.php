<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Structural checks for the Drush command wiring and its config.
 *
 * Runs without a Drupal bootstrap, so everything here is parsed YAML —
 * never raw source text. ScoltaCommands itself cannot even be reflected in
 * this environment (its parent Drush\Commands\DrushCommands is a require-dev
 * dependency absent from the local vendor), so command registration, names,
 * and aliases are proven behaviorally by
 * \Drupal\Tests\scolta\Functional\ScoltaDrushCommandsTest.
 */
class ScoltaCommandsValidationTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // drush.services.yml service definition.
  // -------------------------------------------------------------------

  public function testDrushServiceIsTaggedAsCommand(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $tags = $drush['services']['scolta.commands']['tags'] ?? [];

    $hasTag = FALSE;
    foreach ($tags as $tag) {
      if (($tag['name'] ?? '') === 'drush.command') {
        $hasTag = TRUE;
        break;
      }
    }
    $this->assertTrue($hasTag, 'scolta.commands should be tagged with drush.command');
  }

  public function testDrushServiceClassIsCorrect(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $this->assertEquals(
      'Drupal\scolta\Commands\ScoltaCommands',
      $drush['services']['scolta.commands']['class']
    );
  }

  // -------------------------------------------------------------------
  // Config schema and install defaults carry the memory-budget keys.
  // -------------------------------------------------------------------

  public function testConfigSchemaHasMemoryBudgetChunkSize(): void {
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');
    $budget = $schema['scolta.settings']['mapping']['memory_budget']['mapping'] ?? [];
    $this->assertArrayHasKey('chunk_size', $budget,
      'Config schema must declare memory_budget.chunk_size');
    $this->assertSame('integer', $budget['chunk_size']['type'] ?? NULL);
  }

  public function testConfigInstallHasMemoryBudgetChunkSize(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $this->assertArrayHasKey('chunk_size', $install['memory_budget'] ?? [],
      'Default config must include memory_budget.chunk_size');
  }

  public function testDefaultBuildDirIsPublic(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $this->assertSame('public://scolta-build', $install['pagefind']['build_dir'] ?? NULL,
      'Default install config must use public://scolta-build as build_dir');
  }

}
