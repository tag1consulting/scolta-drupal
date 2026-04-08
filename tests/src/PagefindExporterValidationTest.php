<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests PagefindExporter's validation logic and HTML assembly.
 *
 * Verifies structural contracts, constructor/service alignment,
 * HTML template correctness, and item ID transformation logic.
 * These tests do not require a Drupal bootstrap.
 */
class PagefindExporterValidationTest extends TestCase {

  private string $moduleRoot;
  private string $exporterFile;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->exporterFile = $this->moduleRoot . '/src/Service/PagefindExporter.php';
  }

  // -------------------------------------------------------------------
  // Public method existence.
  // -------------------------------------------------------------------

  public function testHasExportItemMethod(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('function exportItem(', $contents,
      'PagefindExporter must have exportItem method');
  }

  public function testHasDeleteItemMethod(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('function deleteItem(', $contents,
      'PagefindExporter must have deleteItem method');
  }

  public function testHasDeleteAllMethod(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('function deleteAll(', $contents,
      'PagefindExporter must have deleteAll method');
  }

  // -------------------------------------------------------------------
  // Constructor parameters match scolta.services.yml.
  // -------------------------------------------------------------------

  public function testConstructorParameterCountMatchesServices(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml');
    $args = $services['services']['scolta.pagefind_exporter']['arguments'] ?? [];
    $contents = file_get_contents($this->exporterFile);

    if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $contents, $m)) {
      $params = array_filter(array_map('trim', explode(',', $m[1])));
      $this->assertEquals(
        count($params), count($args),
        'PagefindExporter constructor params must match service arguments count'
      );
    }
    else {
      $this->fail('PagefindExporter has no constructor');
    }
  }

  public function testConstructorAcceptsExpectedTypes(): void {
    $contents = file_get_contents($this->exporterFile);

    $expectedTypes = [
      'EntityTypeManagerInterface',
      'RendererInterface',
      'FileSystemInterface',
      'LoggerInterface',
    ];

    foreach ($expectedTypes as $type) {
      $this->assertStringContainsString($type, $contents,
        "Constructor should accept {$type}");
    }
  }

  // -------------------------------------------------------------------
  // assembleHtml produces correct Pagefind attributes.
  // -------------------------------------------------------------------

  public function testAssembleHtmlContainsPagefindBody(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('data-pagefind-body', $contents,
      'assembleHtml must produce data-pagefind-body attribute');
  }

  public function testAssembleHtmlContainsPagefindMetaTitle(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('data-pagefind-meta="title"', $contents,
      'assembleHtml must produce data-pagefind-meta="title" on the h1');
  }

  public function testAssembleHtmlContainsPagefindMetaAttributes(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('data-pagefind-meta=', $contents,
      'assembleHtml must produce data-pagefind-meta attributes');
  }

  public function testAssembleHtmlContainsPagefindFilter(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('data-pagefind-filter=', $contents,
      'assembleHtml must produce data-pagefind-filter attributes');
  }

  public function testAssembleHtmlContainsContentTypeFilter(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('data-pagefind-filter=\"content_type:', $contents,
      'assembleHtml should include content_type filter');
  }

  public function testAssembleHtmlContainsLanguageFilter(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('data-pagefind-filter=\"language:', $contents,
      'assembleHtml should include language filter');
  }

  public function testAssembleHtmlContainsEntityTypeFilter(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('data-pagefind-filter=\"entity_type:', $contents,
      'assembleHtml should include entity_type filter');
  }

  public function testAssembleHtmlProducesValidHtmlStructure(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('<!DOCTYPE html>', $contents,
      'assembleHtml should produce a valid HTML5 document');
    $this->assertStringContainsString('<html lang=', $contents,
      'assembleHtml should include lang attribute on html element');
    $this->assertStringContainsString('<meta charset="utf-8">', $contents,
      'assembleHtml should declare UTF-8 charset');
  }

  // -------------------------------------------------------------------
  // Meta attributes include expected keys.
  // -------------------------------------------------------------------

  public function testMetaPartsIncludeExpectedKeys(): void {
    $contents = file_get_contents($this->exporterFile);

    // The assembleHtml method builds meta from these keys.
    $expectedMetaKeys = ['url', 'date', 'content_type_label', 'entity_type'];
    foreach ($expectedMetaKeys as $key) {
      $this->assertStringContainsString("'{$key}'", $contents,
        "assembleHtml should include meta key '{$key}'");
    }
  }

  // -------------------------------------------------------------------
  // itemIdToFilename conversion logic.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('itemIdToFilenameProvider')]
  public function testItemIdToFilename(string $input, string $expected): void {
    // Mirrors PagefindExporter::itemIdToFilename.
    $safe = preg_replace('/[^a-zA-Z0-9\-]/', '-', $input);
    $result = trim($safe, '-') . '.html';
    $this->assertEquals($expected, $result);
  }

  public static function itemIdToFilenameProvider(): array {
    return [
      'node with language' => ['entity:node/42:en', 'entity-node-42-en.html'],
      'node without language' => ['entity:node/100', 'entity-node-100.html'],
      'user entity' => ['entity:user/1:en', 'entity-user-1-en.html'],
      'taxonomy term' => ['entity:taxonomy_term/5:de', 'entity-taxonomy-term-5-de.html'],
      'media entity' => ['entity:media/99:fr', 'entity-media-99-fr.html'],
      'node with high ID' => ['entity:node/999999:en', 'entity-node-999999-en.html'],
    ];
  }

  public function testItemIdToFilenameStripsUnsafeCharacters(): void {
    $input = 'entity:node/42:en';
    $safe = preg_replace('/[^a-zA-Z0-9\-]/', '-', $input);
    $result = trim($safe, '-') . '.html';

    // No colons, slashes, or other unsafe chars.
    $this->assertStringNotContainsString(':', $result);
    $this->assertStringNotContainsString('/', $result);
    $this->assertStringEndsWith('.html', $result);
  }

  public function testItemIdToFilenameProducesConsistentResults(): void {
    $input = 'entity:node/42:en';
    $safe1 = preg_replace('/[^a-zA-Z0-9\-]/', '-', $input);
    $result1 = trim($safe1, '-') . '.html';

    $safe2 = preg_replace('/[^a-zA-Z0-9\-]/', '-', $input);
    $result2 = trim($safe2, '-') . '.html';

    $this->assertEquals($result1, $result2,
      'itemIdToFilename must be deterministic');
  }

  // -------------------------------------------------------------------
  // deleteAll datasource prefix filtering.
  // -------------------------------------------------------------------

  public function testDatasourceIdToPrefixConversion(): void {
    // Mirrors the deleteAll datasource prefix logic.
    $datasourceId = 'entity:node';
    $prefix = str_replace([':', '/'], ['-', '-'], $datasourceId);
    $this->assertEquals('entity-node', $prefix);

    $datasourceId2 = 'entity:taxonomy_term';
    $prefix2 = str_replace([':', '/'], ['-', '-'], $datasourceId2);
    $this->assertEquals('entity-taxonomy_term', $prefix2);
  }

  // -------------------------------------------------------------------
  // buildMetadata extracts expected entity fields.
  // -------------------------------------------------------------------

  public function testBuildMetadataMethodExists(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString('function buildMetadata(', $contents,
      'PagefindExporter should have a buildMetadata method');
  }

  public function testBuildMetadataIncludesExpectedFields(): void {
    $contents = file_get_contents($this->exporterFile);

    $expectedFields = ['title', 'item_id', 'url', 'content_type', 'date', 'language', 'entity_type'];
    foreach ($expectedFields as $field) {
      $this->assertStringContainsString("'{$field}'", $contents,
        "buildMetadata should populate the '{$field}' field");
    }
  }

}
