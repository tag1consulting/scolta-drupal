<?php

declare(strict_types=1);

// The unit-test environment runs without a Drupal bootstrap. drupal/core
// classes are autoloadable from vendor, but drupal/search_api's are not (it
// is a module, not a composer library with a registered namespace), so the
// one search_api interface the exporter's signature needs is stubbed when
// absent — the same pattern ScoltaRebuildWorkerTest uses. The stub carries
// the minimal method signatures the exporter calls.
// phpcs:disable
namespace Drupal\search_api\Item {
    if (!interface_exists(ItemInterface::class)) {
        interface ItemInterface {
            public function getId();
            public function getOriginalObject($load = TRUE);
            public function getDatasourceId();
        }
    }
}
// phpcs:enable

namespace Drupal\scolta\Tests {

  use Drupal\Core\Entity\EntityInterface;
  use Drupal\Core\Entity\EntityTypeInterface;
  use Drupal\Core\Entity\EntityTypeManagerInterface;
  use Drupal\Core\Entity\EntityViewBuilderInterface;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\Core\Language\LanguageInterface;
  use Drupal\Core\Render\RendererInterface;
  use Drupal\Core\TypedData\ComplexDataInterface;
  use Drupal\scolta\Service\PagefindExporter;
  use Drupal\search_api\Item\ItemInterface;
  use PHPUnit\Framework\TestCase;
  use Psr\Log\NullLogger;
  use Symfony\Component\Yaml\Yaml;

  /**
   * Behavioral tests for the real PagefindExporter.
   *
   * The exporter is constructed with stubbed services; assertions are on the
   * HTML it writes, the files it deletes, and the values its helpers return.
   * The URL branch of buildMetadata() (setAbsolute / root-relative) is not
   * covered here: Url::toString() needs the Drupal container, which this
   * suite does not bootstrap.
   */
  class PagefindExporterValidationTest extends TestCase {

    private string $tmpDir;

    protected function setUp(): void {
      $this->tmpDir = sys_get_temp_dir() . '/scolta-exporter-' . uniqid();
      mkdir($this->tmpDir, 0755, TRUE);
    }

    protected function tearDown(): void {
      $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void {
      if (!is_dir($dir)) {
        return;
      }
      $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
      }
      rmdir($dir);
    }

    /**
     * Builds the real exporter with stubbed services.
     *
     * @param string $renderedHtml
     *   What the stubbed renderer returns for any entity.
     */
    private function createExporter(string $renderedHtml = '<p>Body text</p>'): PagefindExporter {
      $viewBuilder = $this->createStub(EntityViewBuilderInterface::class);
      $viewBuilder->method('view')->willReturn([]);

      $entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
      $entityTypeManager->method('getViewBuilder')->willReturn($viewBuilder);

      $renderer = $this->createStub(RendererInterface::class);
      $renderer->method('renderInIsolation')->willReturn($renderedHtml);

      // Deleting is delegated to the injected filesystem service; route it to
      // the real filesystem so deletions are observable.
      $fileSystem = $this->createStub(FileSystemInterface::class);
      $fileSystem->method('delete')->willReturnCallback(
        static fn (string $path): bool => unlink($path)
      );

      return new PagefindExporter($entityTypeManager, $renderer, $fileSystem, new NullLogger());
    }

    /**
     * A stub entity with no canonical link template and no bundle.
     */
    private function createEntity(string $label = 'Hello World'): EntityInterface {
      $entityType = $this->createStub(EntityTypeInterface::class);
      $entityType->method('getKey')->willReturn(FALSE);

      $language = $this->createStub(LanguageInterface::class);
      $language->method('getId')->willReturn('en');

      $entity = $this->createStub(EntityInterface::class);
      $entity->method('label')->willReturn($label);
      $entity->method('hasLinkTemplate')->willReturn(FALSE);
      $entity->method('getEntityType')->willReturn($entityType);
      $entity->method('language')->willReturn($language);
      $entity->method('getEntityTypeId')->willReturn('node');
      return $entity;
    }

    /**
     * A stub Search API item wrapping the given entity.
     */
    private function createItem(EntityInterface $entity, string $id = 'entity:node/42:en'): ItemInterface {
      $original = $this->createStub(ComplexDataInterface::class);
      $original->method('getValue')->willReturn($entity);

      $item = $this->createStub(ItemInterface::class);
      $item->method('getId')->willReturn($id);
      $item->method('getOriginalObject')->willReturn($original);
      return $item;
    }

    // -------------------------------------------------------------------
    // Constructor matches scolta.services.yml.
    // -------------------------------------------------------------------

    public function testConstructorParameterCountMatchesServices(): void {
      $services = Yaml::parseFile(dirname(__DIR__, 2) . '/scolta.services.yml');
      $args = $services['services']['scolta.pagefind_exporter']['arguments'] ?? [];

      $constructor = new \ReflectionMethod(PagefindExporter::class, '__construct');
      $this->assertSame(
        count($args),
        $constructor->getNumberOfParameters(),
        'PagefindExporter constructor params must match service arguments count'
      );
    }

    public function testConstructorAcceptsExpectedTypes(): void {
      $constructor = new \ReflectionMethod(PagefindExporter::class, '__construct');
      $types = array_map(
        static fn (\ReflectionParameter $p): string => (string) $p->getType(),
        $constructor->getParameters()
      );

      $this->assertSame(
        [
          EntityTypeManagerInterface::class,
          RendererInterface::class,
          FileSystemInterface::class,
          'Psr\Log\LoggerInterface',
        ],
        $types
      );
    }

    // -------------------------------------------------------------------
    // exportItem() writes real Pagefind HTML.
    // -------------------------------------------------------------------

    public function testExportItemWritesPagefindHtml(): void {
      $exporter = $this->createExporter('<p>Body text</p>');
      $item = $this->createItem($this->createEntity('Hello & World'));

      $exporter->exportItem($item, $this->tmpDir);

      // No canonical URL on the stub entity, so the flat fallback filename
      // derived from the item ID is used.
      $filepath = $this->tmpDir . '/entity-node-42-en.html';
      $this->assertFileExists($filepath);
      $html = file_get_contents($filepath);

      $this->assertStringContainsString('<!DOCTYPE html>', $html);
      $this->assertStringContainsString('<html lang="en">', $html);
      $this->assertStringContainsString('<meta charset="utf-8">', $html);
      // The body wrapper Pagefind indexes.
      $this->assertStringContainsString('<div data-pagefind-body>', $html);
      $this->assertStringContainsString('<p>Body text</p>', $html);
      // The escaped title, as both <title> and the pagefind title meta.
      $this->assertStringContainsString('<title>Hello &amp; World</title>', $html);
      $this->assertStringContainsString('<h1 data-pagefind-meta="title">Hello &amp; World</h1>', $html);
      // Facet filters: language and entity type (no bundle on this entity).
      $this->assertStringContainsString('data-pagefind-filter="language:en"', $html);
      $this->assertStringContainsString('data-pagefind-filter="entity_type:node"', $html);
      // The combined meta attribute carries the entity type.
      $this->assertStringContainsString('entity-type:node', $html);
    }

    public function testExportItemSkipsEmptyRenderedContent(): void {
      $exporter = $this->createExporter('   ');
      $item = $this->createItem($this->createEntity());

      $exporter->exportItem($item, $this->tmpDir);

      $this->assertSame(
        [],
        glob($this->tmpDir . '/*'),
        'An item that renders to empty content must not produce a file'
      );
    }

    // -------------------------------------------------------------------
    // buildMetadata() — the real protected method via reflection.
    // -------------------------------------------------------------------

    public function testBuildMetadataReturnsExpectedKeys(): void {
      $method = new \ReflectionMethod(PagefindExporter::class, 'buildMetadata');
      $entity = $this->createEntity('A Title');
      $item = $this->createItem($entity);

      $meta = $method->invoke($this->createExporter(), $entity, $item);

      $this->assertSame('A Title', $meta['title']);
      $this->assertSame('entity:node/42:en', $meta['item_id']);
      $this->assertSame('en', $meta['language']);
      $this->assertSame('node', $meta['entity_type']);
      // No canonical link template on the stub, so no URL is recorded.
      $this->assertArrayNotHasKey('url', $meta);
    }

    public function testBuildMetadataFallsBackToUntitled(): void {
      $method = new \ReflectionMethod(PagefindExporter::class, 'buildMetadata');
      $entity = $this->createEntity('');
      $item = $this->createItem($entity);

      $meta = $method->invoke($this->createExporter(), $entity, $item);

      $this->assertSame('Untitled', $meta['title']);
    }

    // -------------------------------------------------------------------
    // itemIdToFilename() — the real protected method via reflection.
    // -------------------------------------------------------------------

    /**
     * @dataProvider itemIdToFilenameProvider
     */
    public function testItemIdToFilename(string $input, string $expected): void {
      $method = new \ReflectionMethod(PagefindExporter::class, 'itemIdToFilename');

      $this->assertSame($expected, $method->invoke($this->createExporter(), $input));
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

    // -------------------------------------------------------------------
    // deleteItem() / deleteAll() against real files.
    // -------------------------------------------------------------------

    public function testDeleteItemRemovesFlatFallbackFile(): void {
      // No manifest in the build dir, so deleteItem falls back to the flat
      // filename derived from the item ID.
      $filepath = $this->tmpDir . '/entity-node-42-en.html';
      file_put_contents($filepath, '<html></html>');
      file_put_contents($this->tmpDir . '/entity-node-43-en.html', '<html></html>');

      $this->createExporter()->deleteItem('entity:node/42:en', $this->tmpDir);

      $this->assertFileDoesNotExist($filepath);
      $this->assertFileExists($this->tmpDir . '/entity-node-43-en.html');
    }

    public function testDeleteItemUsesManifestForNestedLayout(): void {
      mkdir($this->tmpDir . '/node/42', 0755, TRUE);
      file_put_contents($this->tmpDir . '/node/42/index.html', '<html></html>');
      file_put_contents(
        $this->tmpDir . '/.scolta-export-manifest.json',
        json_encode(['entity:node/42:en' => 'node/42/index.html'])
      );

      $this->createExporter()->deleteItem('entity:node/42:en', $this->tmpDir);

      $this->assertFileDoesNotExist($this->tmpDir . '/node/42/index.html');
    }

    public function testDeleteAllRemovesNestedHtmlFilesOnly(): void {
      mkdir($this->tmpDir . '/node/1', 0755, TRUE);
      mkdir($this->tmpDir . '/node/2', 0755, TRUE);
      file_put_contents($this->tmpDir . '/node/1/index.html', '<html></html>');
      file_put_contents($this->tmpDir . '/node/2/index.html', '<html></html>');
      file_put_contents($this->tmpDir . '/flat.html', '<html></html>');
      file_put_contents($this->tmpDir . '/keep-me.txt', 'not html');

      $this->createExporter()->deleteAll($this->tmpDir);

      $this->assertFileDoesNotExist($this->tmpDir . '/node/1/index.html');
      $this->assertFileDoesNotExist($this->tmpDir . '/node/2/index.html');
      $this->assertFileDoesNotExist($this->tmpDir . '/flat.html');
      $this->assertFileExists($this->tmpDir . '/keep-me.txt');
    }

    public function testDeleteAllToleratesMissingDirectory(): void {
      $this->createExporter()->deleteAll($this->tmpDir . '/no-such-dir');

      // No exception is the assertion; the directory is still absent.
      $this->assertDirectoryDoesNotExist($this->tmpDir . '/no-such-dir');
    }

  }

}
