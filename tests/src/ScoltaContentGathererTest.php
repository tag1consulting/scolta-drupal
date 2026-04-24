<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for ScoltaContentGatherer.
 *
 * Verifies the service class structure, service registration, and that it
 * is properly injected into ScoltaCommands via drush.services.yml.
 * Full functional tests require a Drupal bootstrap; these tests use
 * file inspection and reflection in line with the rest of the test suite.
 */
class ScoltaContentGathererTest extends TestCase {

  private string $moduleRoot;
  private string $gathererFile;
  private string $gathererContents;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->gathererFile = $this->moduleRoot . '/src/Service/ScoltaContentGatherer.php';
    $this->gathererContents = file_get_contents($this->gathererFile);
  }

  // -------------------------------------------------------------------
  // Class structure
  // -------------------------------------------------------------------

  public function testGathererFileExists(): void {
    $this->assertFileExists(
      $this->gathererFile,
      'ScoltaContentGatherer.php must exist in src/Service/'
    );
  }

  public function testGathererHasGatherMethod(): void {
    $this->assertStringContainsString(
      'function gather(',
      $this->gathererContents,
      'ScoltaContentGatherer must have gather() method'
    );
  }

  public function testGatherMethodSignature(): void {
    $this->assertStringContainsString(
      'public function gather(string $entityType, string $bundle, string $siteName): \Generator',
      $this->gathererContents,
      'gather() must accept entityType, bundle, siteName and return \\Generator (not array)'
    );
  }

  public function testGatherCountMethodExists(): void {
    $this->assertStringContainsString(
      'public function gatherCount(string $entityType, string $bundle): int',
      $this->gathererContents,
      'gatherCount() must exist with int return type'
    );
  }

  public function testGatherDoesNotUseLoadMultipleWithoutPagination(): void {
    // gather() must use range() pagination, not a single loadMultiple of all IDs.
    $this->assertStringContainsString(
      '->range(',
      $this->gathererContents,
      'gather() must use range() to paginate instead of loading all entities at once'
    );
  }

  public function testGatherResetsEntityCacheBetweenBatches(): void {
    $this->assertStringContainsString(
      'resetCache(',
      $this->gathererContents,
      'gather() must call resetCache() between batches to release field data from RAM'
    );
  }

  public function testGathererConstructorAcceptsEntityTypeManager(): void {
    $this->assertStringContainsString(
      'EntityTypeManagerInterface',
      $this->gathererContents,
      'ScoltaContentGatherer constructor must accept EntityTypeManagerInterface'
    );
  }

  public function testGathererReturnsContentItems(): void {
    $this->assertStringContainsString(
      'ContentItem',
      $this->gathererContents,
      'gather() must create and return ContentItem objects'
    );
  }

  public function testGathererQueriesPublishedEntities(): void {
    $this->assertStringContainsString(
      "condition('status', 1)",
      $this->gathererContents,
      'gather() must filter for published (status=1) entities'
    );
  }

  public function testGathererHandlesBundleFilter(): void {
    $this->assertStringContainsString(
      '$bundle',
      $this->gathererContents,
      'gather() must support bundle filtering'
    );
  }

  // -------------------------------------------------------------------
  // Service container registration
  // -------------------------------------------------------------------

  public function testServiceIsRegisteredInServicesYml(): void {
    $servicesYml = file_get_contents($this->moduleRoot . '/scolta.services.yml');

    $this->assertStringContainsString(
      'scolta.content_gatherer',
      $servicesYml,
      'scolta.content_gatherer service must be defined in scolta.services.yml'
    );
  }

  public function testServiceClassInServicesYml(): void {
    $servicesYml = file_get_contents($this->moduleRoot . '/scolta.services.yml');

    $this->assertStringContainsString(
      'Drupal\\scolta\\Service\\ScoltaContentGatherer',
      $servicesYml,
      'scolta.content_gatherer must reference ScoltaContentGatherer class'
    );
  }

  public function testServiceArgumentIsEntityTypeManager(): void {
    $servicesYml = file_get_contents($this->moduleRoot . '/scolta.services.yml');

    // The gatherer entry must have @entity_type.manager as its argument.
    $this->assertMatchesRegularExpression(
      '/scolta\.content_gatherer:.*?arguments:.*?entity_type\.manager/s',
      $servicesYml,
      'scolta.content_gatherer must inject @entity_type.manager'
    );
  }

  // -------------------------------------------------------------------
  // Injection into ScoltaCommands
  // -------------------------------------------------------------------

  public function testDrushServicesYmlInjectsGathererIntoCommands(): void {
    $drushYml = file_get_contents($this->moduleRoot . '/drush.services.yml');

    $this->assertStringContainsString(
      'scolta.content_gatherer',
      $drushYml,
      'drush.services.yml must pass @scolta.content_gatherer to ScoltaCommands'
    );
  }

  public function testScoltaCommandsImportsGatherer(): void {
    $commandsFile = file_get_contents($this->moduleRoot . '/src/Commands/ScoltaCommands.php');

    $this->assertStringContainsString(
      'use Drupal\\scolta\\Service\\ScoltaContentGatherer',
      $commandsFile,
      'ScoltaCommands must import ScoltaContentGatherer'
    );
  }

  public function testScoltaCommandsCallsGather(): void {
    $commandsFile = file_get_contents($this->moduleRoot . '/src/Commands/ScoltaCommands.php');

    $this->assertStringContainsString(
      '$this->contentGatherer->gather(',
      $commandsFile,
      'ScoltaCommands must delegate to contentGatherer->gather()'
    );
  }

  public function testScoltaCommandsNoLongerHasPrivateGatherMethod(): void {
    $commandsFile = file_get_contents($this->moduleRoot . '/src/Commands/ScoltaCommands.php');

    $this->assertStringNotContainsString(
      'private function gatherContentItems(',
      $commandsFile,
      'ScoltaCommands must not retain the now-dead private gatherContentItems() method'
    );
  }

}
