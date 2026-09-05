<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\search_api\Item\ItemInterface;
use Psr\Log\LoggerInterface;
use Tag1\Scolta\Export\ContentExporter;

/**
 * Exports Search API items as HTML files with Pagefind data attributes.
 *
 * Each indexed item becomes a minimal HTML file containing:
 * - data-pagefind-body: the rendered content (from the entity's view mode)
 * - data-pagefind-meta: title, URL, content type, date, language
 * - data-pagefind-filter: facetable attributes (content_type, language)
 *
 * Files are written to a build directory. After export, the Pagefind CLI
 * processes this directory to produce the static search index.
 */
class PagefindExporter {

  /**
   * Name of the export manifest file, as read by ContentExporter.
   *
   * ContentExporter::readManifest() looks for this file; its writer is an
   * instance method that serialises only the paths that one ContentExporter
   * object exported, and this adapter renders through Drupal rather than
   * through ContentExporter::export(). So the name is repeated here and the
   * file is written by writeManifest() below. Keep the two in step.
   */
  protected const MANIFEST_FILENAME = '.scolta-export-manifest.json';

  /**
   * Manifest entries, keyed by build directory then by Search API item ID.
   *
   * Seeded from the manifest already on disk the first time a build directory
   * is touched, then updated by exportItem() and deleteItem(). Search API
   * hands the backend a batch at a time, so a manifest built only from what
   * one request exported would drop every item the request did not touch and
   * leave those pages undeletable; merging into what is already there keeps
   * the map covering the whole directory.
   *
   * @var array<string, array<string, string>>
   */
  protected array $manifests = [];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RendererInterface $renderer,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Export a single Search API item as an HTML file.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The Search API item (contains the entity and processed field data).
   * @param string $buildDir
   *   Absolute path to the build directory.
   * @param string $viewMode
   *   The entity view mode to use for rendering (e.g., 'search_index').
   */
  public function exportItem(ItemInterface $item, string $buildDir, string $viewMode = 'search_index'): void {
    $entity = $this->extractEntity($item);
    if (!$entity) {
      $this->logger->warning('Could not extract entity from item @id', [
        '@id' => $item->getId(),
      ]);
      return;
    }

    // Render the entity using Drupal's view builder.
    $renderedHtml = $this->renderEntity($entity, $viewMode);
    if (empty(trim(PlainTextOutput::renderFromHtml($renderedHtml)))) {
      $this->logger->notice('Item @id rendered to empty content, skipping.', [
        '@id' => $item->getId(),
      ]);
      return;
    }

    // Build metadata.
    $meta = $this->buildMetadata($entity, $item);

    // Assemble the HTML file.
    $html = $this->assembleHtml($meta, $renderedHtml);

    // Write to disk using nested directory layout mirroring the canonical URL.
    $relativePath = isset($meta['url'])
      ? self::urlToExportPath($meta['url'])
      : $this->itemIdToFilename($item->getId());
    $filepath = rtrim($buildDir, '/') . '/' . $relativePath;
    $this->ensureDirectory(dirname($filepath));
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- absolute path outside Drupal stream wrappers; saveData() requires a URI scheme.
    if (file_put_contents($filepath, $html) === FALSE) {
      throw new \RuntimeException("Failed to write export file: {$filepath}");
    }

    // Record where this item landed, under the same ID the metadata carries.
    // Only writeManifest() persists it, so a caller that exports a batch pays
    // one manifest write for the batch.
    $dir = rtrim($buildDir, '/');
    $this->loadManifest($dir);
    $this->manifests[$dir][$meta['item_id']] = $relativePath;
  }

  /**
   * Write the export manifest for a build directory.
   *
   * Persists the item ID → export path map that deleteItem() reads. Call it
   * once after a run of exportItem() or deleteItem() calls; without it the
   * nested export paths are unrecoverable and deleted content keeps its HTML
   * file, and so keeps being indexed.
   *
   * Entries already on disk are preserved, so this is safe to call after a
   * partial export. It is not the same contract as
   * ContentExporter::writeManifest(), which replaces the file with the paths
   * of one exporter instance and is therefore only correct after a run that
   * exported the whole directory.
   *
   * @param string $buildDir
   *   Absolute path to the build directory.
   *
   * @throws \RuntimeException
   *   If the manifest cannot be written.
   *
   * @since 1.4.0
   * @stability experimental
   */
  public function writeManifest(string $buildDir): void {
    $dir = rtrim($buildDir, '/');
    $this->loadManifest($dir);
    $this->ensureDirectory($dir);

    $manifestPath = $dir . '/' . self::MANIFEST_FILENAME;
    $json = json_encode($this->manifests[$dir], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- absolute path outside Drupal stream wrappers; saveData() requires a URI scheme.
    if ($json === FALSE || file_put_contents($manifestPath, $json) === FALSE) {
      throw new \RuntimeException("Failed to write export manifest: {$manifestPath}");
    }
  }

  /**
   * Seed the in-memory manifest for a build directory from disk, once.
   *
   * @param string $dir
   *   The build directory, without a trailing slash.
   */
  protected function loadManifest(string $dir): void {
    if (!isset($this->manifests[$dir])) {
      $this->manifests[$dir] = ContentExporter::readManifest($dir);
    }
  }

  /**
   * Delete the HTML file for a given item ID.
   *
   * Looks up the ID in the export manifest to find the nested path. Falls
   * back to flat {id}.html, the layout exports used before 1.1.0; a directory
   * exported since then has no such file, so the fallback only ever fires for
   * an index that has not been rebuilt since.
   *
   * The manifest entry is dropped either way. Call writeManifest() afterwards
   * to persist that.
   *
   * @param string $itemId
   *   The Search API item ID.
   * @param string $buildDir
   *   Absolute path to the build directory.
   *
   * @return bool
   *   TRUE if a file was deleted. FALSE means nothing was found to delete:
   *   the item was never exported, or its file is on disk under a path no
   *   manifest records, in which case it stays indexable.
   *
   * @since 1.1.0
   * @stability experimental
   */
  public function deleteItem(string $itemId, string $buildDir): bool {
    $dir = rtrim($buildDir, '/');

    // Try manifest-based lookup first (nested directory layout).
    $this->loadManifest($dir);
    if (isset($this->manifests[$dir][$itemId])) {
      $filepath = $dir . '/' . $this->manifests[$dir][$itemId];
      unset($this->manifests[$dir][$itemId]);
      if (file_exists($filepath)) {
        $this->fileSystem->delete($filepath);
        return TRUE;
      }
    }

    // Fall back to flat filename for backward compatibility.
    $filepath = $dir . '/' . $this->itemIdToFilename($itemId);
    if (file_exists($filepath)) {
      $this->fileSystem->delete($filepath);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Delete all HTML files in the build directory.
   *
   * Uses a recursive directory walk to find HTML files in the nested
   * directory layout. The datasourceId filter is ignored because the
   * datasource prefix was only meaningful for flat filenames; in the
   * nested layout all files are index.html.
   *
   * @param string $buildDir
   *   The build directory path.
   * @param string|null $datasourceId
   *   Optional datasource filter (ignored in nested layout).
   *
   * @since 1.1.0
   * @stability experimental
   */
  public function deleteAll(string $buildDir, ?string $datasourceId = NULL): void {
    if (!is_dir($buildDir)) {
      return;
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($buildDir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
      if ($file->isFile() && $file->getExtension() === 'html') {
        $this->fileSystem->delete($file->getPathname());
      }
    }

    // The manifest described the files just removed. Leaving it would have
    // deleteItem() resolve IDs to paths that no longer exist.
    $dir = rtrim($buildDir, '/');
    $manifestPath = $dir . '/' . self::MANIFEST_FILENAME;
    if (file_exists($manifestPath)) {
      $this->fileSystem->delete($manifestPath);
    }
    $this->manifests[$dir] = [];
  }

  /**
   * Extract the Drupal entity from a Search API item.
   */
  protected function extractEntity(ItemInterface $item): ?EntityInterface {
    $originalObject = $item->getOriginalObject();
    if (!$originalObject) {
      // Item was loaded without the original object. Load it.
      $datasourceId = $item->getDatasourceId();
      if (!$datasourceId) {
        return NULL;
      }

      // Parse the item ID to get entity type and ID.
      // Search API item IDs look like "entity:node/42:en".
      $parts = explode('/', $item->getId());
      if (count($parts) < 2) {
        return NULL;
      }

      $entityId = explode(':', $parts[1])[0];
      $entityType = str_replace('entity:', '', $datasourceId);

      $storage = $this->entityTypeManager->getStorage($entityType);
      return $storage->load($entityId);
    }

    $value = $originalObject->getValue();
    if ($value instanceof EntityInterface) {
      return $value;
    }

    return NULL;
  }

  /**
   * Render an entity to HTML using Drupal's view builder.
   */
  protected function renderEntity(EntityInterface $entity, string $viewMode): string {
    $entityTypeId = $entity->getEntityTypeId();
    $viewBuilder = $this->entityTypeManager->getViewBuilder($entityTypeId);
    $build = $viewBuilder->view($entity, $viewMode);

    // Render in isolation to avoid page-level side effects.
    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Build metadata array for a given entity.
   */
  protected function buildMetadata(EntityInterface $entity, ItemInterface $item): array {
    $meta = [
      'title' => $entity->label() ?: 'Untitled',
      'item_id' => $item->getId(),
    ];

    // URL — root-relative so Pagefind stores it verbatim in data-pagefind-meta.
    // Absolute URLs cause doubling on subdirectory installs because Pagefind
    // strips the domain, stores the path, then its JS resolves that path
    // against the pagefind base, producing /base/path/drupal/web/node/42.
    if ($entity->hasLinkTemplate('canonical')) {
      try {
        $meta['url'] = $entity->toUrl('canonical')->toString();
      }
      catch (\Exception $e) {
        // Some entities may not generate URLs cleanly.
      }
    }

    // Content type (bundle).
    $entityType = $entity->getEntityType();
    $bundleKey = $entityType->getKey('bundle');
    if ($bundleKey && $entity instanceof FieldableEntityInterface && $entity->hasField($bundleKey)) {
      $meta['content_type'] = $entity->bundle();
      // Human-readable bundle label.
      $bundleEntity = $this->entityTypeManager
        ->getStorage($entityType->getBundleEntityType())
        ->load($entity->bundle());
      if ($bundleEntity) {
        $meta['content_type_label'] = $bundleEntity->label();
      }
    }

    // Date — use changed timestamp if available, fallback to created.
    if (method_exists($entity, 'getChangedTime')) {
      $meta['date'] = date('Y-m-d', $entity->getChangedTime());
    }
    elseif (method_exists($entity, 'getCreatedTime')) {
      $meta['date'] = date('Y-m-d', $entity->getCreatedTime());
    }

    // Language.
    $meta['language'] = $entity->language()->getId();

    // Entity type for multi-type indexes.
    $meta['entity_type'] = $entity->getEntityTypeId();

    return $meta;
  }

  /**
   * Assemble a complete HTML document with Pagefind attributes.
   */
  protected function assembleHtml(array $meta, string $renderedContent): string {
    $title = htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8');
    $lang = htmlspecialchars($meta['language'] ?? 'en', ENT_QUOTES, 'UTF-8');

    // Build data-pagefind-meta attribute value.
    $metaParts = [];
    foreach (['url', 'date', 'content_type_label', 'entity_type'] as $key) {
      if (!empty($meta[$key])) {
        $safeKey = str_replace('_', '-', $key);
        $safeVal = htmlspecialchars((string) $meta[$key], ENT_QUOTES, 'UTF-8');
        $metaParts[] = "{$safeKey}:{$safeVal}";
      }
    }
    $metaAttr = implode(', ', $metaParts);

    // Build filter attributes.
    $filters = '';
    if (!empty($meta['content_type'])) {
      $ct = htmlspecialchars($meta['content_type_label'] ?? $meta['content_type'], ENT_QUOTES, 'UTF-8');
      $filters .= "  <span data-pagefind-filter=\"content_type:{$ct}\" hidden></span>\n";
    }
    if (!empty($meta['language'])) {
      $filters .= "  <span data-pagefind-filter=\"language:{$lang}\" hidden></span>\n";
    }
    if (!empty($meta['entity_type'])) {
      $et = htmlspecialchars($meta['entity_type'], ENT_QUOTES, 'UTF-8');
      $filters .= "  <span data-pagefind-filter=\"entity_type:{$et}\" hidden></span>\n";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
  <meta charset="utf-8">
  <title>{$title}</title>
  <meta data-pagefind-meta="{$metaAttr}">
</head>
<body>
  <h1 data-pagefind-meta="title">{$title}</h1>
{$filters}
  <div data-pagefind-body>
{$renderedContent}
  </div>
</body>
</html>
HTML;
  }

  /**
   * Convert a Search API item ID to a safe filename.
   *
   * "entity:node/42:en" → "entity-node-42-en.html"
   */
  protected function itemIdToFilename(string $itemId): string {
    $safe = preg_replace('/[^a-zA-Z0-9\-]/', '-', $itemId);
    return trim($safe, '-') . '.html';
  }

  /**
   * Ensure a directory exists, creating it recursively if needed.
   */
  protected function ensureDirectory(string $dir): void {
    if (!is_dir($dir)) {
      $this->fileSystem->mkdir($dir, NULL, TRUE);
    }
  }

  /**
   * Map a canonical URL to an export file path.
   *
   * Delegates to ContentExporter::urlToExportPath() so the Drupal adapter
   * produces the same nested directory layout as the shared PHP exporter.
   *
   * @param string $url
   *   Root-relative canonical URL.
   *
   * @return string
   *   Export-relative file path (no leading slash).
   *
   * @since 1.1.0
   * @stability experimental
   */
  public static function urlToExportPath(string $url): string {
    return ContentExporter::urlToExportPath($url);
  }

}
