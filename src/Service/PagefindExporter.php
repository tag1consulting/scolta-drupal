<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\search_api\Item\ItemInterface;
use Psr\Log\LoggerInterface;

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
    if (empty(trim(strip_tags($renderedHtml)))) {
      $this->logger->notice('Item @id rendered to empty content, skipping.', [
        '@id' => $item->getId(),
      ]);
      return;
    }

    // Build metadata.
    $meta = $this->buildMetadata($entity, $item);

    // Assemble the HTML file.
    $html = $this->assembleHtml($meta, $renderedHtml);

    // Write to disk.
    $filename = $this->itemIdToFilename($item->getId());
    $filepath = rtrim($buildDir, '/') . '/' . $filename;
    $this->ensureDirectory(dirname($filepath));
    file_put_contents($filepath, $html);
  }

  /**
   * Delete the HTML file for a given item ID.
   */
  public function deleteItem(string $itemId, string $buildDir): void {
    $filename = $this->itemIdToFilename($itemId);
    $filepath = rtrim($buildDir, '/') . '/' . $filename;
    if (file_exists($filepath)) {
      unlink($filepath);
    }
  }

  /**
   * Delete all HTML files in the build directory.
   *
   * @param string $buildDir
   *   The build directory path.
   * @param string|null $datasourceId
   *   If provided, only delete files from this datasource (e.g., 'entity:node').
   */
  public function deleteAll(string $buildDir, ?string $datasourceId = NULL): void {
    if (!is_dir($buildDir)) {
      return;
    }

    if ($datasourceId) {
      // Delete only files matching the datasource prefix.
      $prefix = str_replace([':', '/'], ['-', '-'], $datasourceId);
      $files = glob($buildDir . '/' . $prefix . '-*.html');
      if ($files) {
        foreach ($files as $file) {
          unlink($file);
        }
      }
    }
    else {
      // Delete all HTML files.
      $files = glob($buildDir . '/*.html');
      if ($files) {
        foreach ($files as $file) {
          unlink($file);
        }
      }
    }
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

    // URL — entities with canonical URLs.
    if ($entity->hasLinkTemplate('canonical')) {
      try {
        $meta['url'] = $entity->toUrl('canonical')
          ->setAbsolute(TRUE)
          ->toString();
      }
      catch (\Exception $e) {
        // Some entities may not generate URLs cleanly.
      }
    }

    // Content type (bundle).
    $entityType = $entity->getEntityType();
    $bundleKey = $entityType->getKey('bundle');
    if ($bundleKey && $entity->hasField($bundleKey)) {
      $meta['content_type'] = $entity->bundle();
      // Human-readable bundle label.
      $bundleEntity = $this->entityTypeManager
        ->getStorage($entityType->getBundleEntityType())
        ?->load($entity->bundle());
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

}
