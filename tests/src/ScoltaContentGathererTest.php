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
    // Core positional parameters must be present.
    $this->assertStringContainsString(
      'public function gather(string $entityType, string $bundle, string $siteName, int $startPage = 0',
      $this->gathererContents,
      'gather() must accept entityType, bundle, siteName, optional startPage'
    );
    // Must return a Generator.
    $this->assertStringContainsString(
      '): \Generator',
      $this->gathererContents,
      'gather() must return \\Generator'
    );
    // TimestampManifest parameter added for incremental optimization.
    $this->assertStringContainsString(
      'TimestampManifest',
      $this->gathererContents,
      'gather() must accept optional TimestampManifest for timestamp-based optimization'
    );
  }

  public function testGathererHasGetEntityTimestamps(): void {
    $this->assertStringContainsString(
      'public function getEntityTimestamps(',
      $this->gathererContents,
      'ScoltaContentGatherer must have getEntityTimestamps() for lightweight timestamp queries'
    );
  }

  public function testGathererInjectsDatabase(): void {
    $this->assertStringContainsString(
      'Connection',
      $this->gathererContents,
      'ScoltaContentGatherer must inject Drupal\\Core\\Database\\Connection'
    );
    $servicesYml = file_get_contents(dirname(__DIR__, 2) . '/scolta.services.yml');
    $this->assertStringContainsString(
      '@database',
      $servicesYml,
      'scolta.content_gatherer service must inject @database'
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

  public function testGathererCastsProcessedToStringBeforePlainTextConversion(): void {
    // Drupal's ->processed returns FilteredMarkup, not a plain string.
    // Must cast to (string) before calling PlainTextOutput::renderFromHtml().
    $this->assertStringContainsString(
      'PlainTextOutput::renderFromHtml((string) $item->processed)',
      $this->gathererContents,
      'gather() must cast ->processed to string before PlainTextOutput::renderFromHtml() to handle FilteredMarkup'
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

  // -------------------------------------------------------------------
  // Entity-agnostic sort: must use generic entity ID key, not 'nid'.
  // -------------------------------------------------------------------

  public function testGatherUsesGenericEntityIdKey(): void {
    $this->assertStringContainsString(
      "->getKey('id')",
      $this->gathererContents,
      'gather() must resolve the entity ID key dynamically instead of hardcoding nid'
    );
    $this->assertStringNotContainsString(
      "->sort('nid'",
      $this->gathererContents,
      'gather() must not hardcode nid — use the generic entity ID key for non-node entity types'
    );
  }

  public function testGatherSortsByIdKey(): void {
    $this->assertStringContainsString(
      '->sort($idKey,',
      $this->gathererContents,
      'gather() must sort by the dynamically resolved entity ID key'
    );
  }

  // -------------------------------------------------------------------
  // Config-driven field mapping.
  // -------------------------------------------------------------------

  public function testGathererInjectsConfigFactory(): void {
    $this->assertStringContainsString(
      'ConfigFactoryInterface',
      $this->gathererContents,
      'ScoltaContentGatherer must inject ConfigFactoryInterface for field_mappings config'
    );
    $servicesYml = file_get_contents(dirname(__DIR__, 2) . '/scolta.services.yml');
    $this->assertStringContainsString(
      '@config.factory',
      $servicesYml,
      'scolta.content_gatherer service must inject @config.factory'
    );
  }

  public function testGathererReadsFieldMappingsConfig(): void {
    $this->assertStringContainsString(
      "->get('field_mappings')",
      $this->gathererContents,
      'gather() must read field_mappings from scolta.settings config'
    );
  }

  public function testGathererHasResolveFieldValueMethod(): void {
    $this->assertStringContainsString(
      'private function resolveFieldValue(',
      $this->gathererContents,
      'ScoltaContentGatherer must have resolveFieldValue() helper for field mapping'
    );
  }

  public function testResolveFieldValueHandlesEntityReferences(): void {
    $this->assertStringContainsString(
      'entity_reference',
      $this->gathererContents,
      'resolveFieldValue() must handle entity_reference field types'
    );
  }

  public function testResolveFieldValueHandlesNumericFields(): void {
    $this->assertStringContainsString(
      "'integer', 'decimal', 'float'",
      $this->gathererContents,
      'resolveFieldValue() must handle numeric field types'
    );
  }

  public function testFieldMappingsAppliedBeforeHook(): void {
    $lines = explode("\n", $this->gathererContents);
    $mappingLine = NULL;
    $hookLine = NULL;
    foreach ($lines as $i => $line) {
      if (str_contains($line, "->get('field_mappings')")) {
        $mappingLine = $i;
      }
      if (str_contains($line, "->alter('scolta_content_item'")) {
        $hookLine = $i;
      }
    }
    $this->assertNotNull($mappingLine, 'field_mappings config read must be present');
    $this->assertNotNull($hookLine, 'hook_scolta_content_item_alter invocation must be present');
    $this->assertLessThan(
      $hookLine,
      $mappingLine,
      'Config-driven field mappings must be applied BEFORE hook_scolta_content_item_alter'
    );
  }

  // -------------------------------------------------------------------
  // URL must be root-relative, not absolute (issue #40).
  // -------------------------------------------------------------------

  public function testGatherDoesNotUseAbsoluteUrls(): void {
    $this->assertStringNotContainsString(
      'setAbsolute(TRUE)',
      $this->gathererContents,
      'gather() must not use setAbsolute(TRUE) — absolute URLs cause path doubling on subdirectory Drupal installs'
    );
  }

  public function testGatherUsesRootRelativeUrls(): void {
    $this->assertMatchesRegularExpression(
      '/->toUrl\(\)->toString\(\)/',
      $this->gathererContents,
      'gather() must call ->toUrl()->toString() to produce root-relative URLs for Pagefind'
    );
  }

  // -------------------------------------------------------------------
  // Hook API documentation (scolta.api.php).
  // -------------------------------------------------------------------

  public function testScoltaApiPhpExists(): void {
    $this->assertFileExists(
      $this->moduleRoot . '/scolta.api.php',
      'scolta.api.php must exist for hook discoverability (standard Drupal practice)'
    );
  }

  public function testScoltaApiPhpDocumentsContentItemAlterHook(): void {
    $contents = file_get_contents($this->moduleRoot . '/scolta.api.php');
    $this->assertStringContainsString(
      'function hook_scolta_content_item_alter(',
      $contents,
      'scolta.api.php must document hook_scolta_content_item_alter()'
    );
  }

  public function testScoltaModuleCrossReferencesApiPhp(): void {
    $moduleContents = file_get_contents($this->moduleRoot . '/scolta.module');
    $this->assertStringContainsString(
      '@see scolta.api.php',
      $moduleContents,
      'scolta.module must cross-reference scolta.api.php for hook documentation'
    );
  }

  public function testHookStubNotDuplicatedInModule(): void {
    $moduleContents = file_get_contents($this->moduleRoot . '/scolta.module');
    $this->assertStringNotContainsString(
      'function hook_scolta_content_item_alter(',
      $moduleContents,
      'hook_scolta_content_item_alter() stub must only be in scolta.api.php, not scolta.module'
    );
  }

}
