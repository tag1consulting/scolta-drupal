<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Tag1\Scolta\Export\ContentItem;

/**
 * Central content gathering service.
 *
 * Single source of truth for collecting indexable content across entity types.
 * Both the Drush command pipeline (PHP indexer) and the legacy HTML-export
 * pipeline delegate to this class so the query logic lives in one place.
 *
 * @since 0.2.0
 * @stability experimental
 */
class ScoltaContentGatherer {

  /**
   * Constructs a ScoltaContentGatherer.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Count published entities without loading their field data.
   *
   * Runs a COUNT-only entity query so that gatherCount() is O(1) in memory.
   * Use this when you need the total before streaming with gather().
   *
   * @param string $entityType
   *   The entity type to query (e.g. 'node').
   * @param string $bundle
   *   The bundle to filter by, or empty string for all bundles.
   *
   * @return int
   *   Total count of published entities matching the given type and bundle.
   *
   * @since 0.3.2
   * @stability experimental
   */
  public function gatherCount(string $entityType, string $bundle): int {
    $storage = $this->entityTypeManager->getStorage($entityType);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->count();

    if ($bundle) {
      $bundleKey = $this->entityTypeManager->getDefinition($entityType)->getKey('bundle');
      if ($bundleKey) {
        $query->condition($bundleKey, $bundle);
      }
    }

    return (int) $query->execute();
  }

  /**
   * Gather indexable content as a generator that yields one ContentItem at a time.
   *
   * Paginates the entity query in batches of 50 and calls resetCache() after
   * each batch so that entity field data from previous batches is released
   * from RAM. Peak RSS stays bounded regardless of corpus size.
   *
   * Callers must NOT convert this generator to an array — that restores
   * the pre-0.3.2 eager-load behaviour. Pass the generator directly to
   * IndexBuildOrchestrator::build() or ContentExporter::filterItems().
   *
   * @param string $entityType
   *   The entity type to query (e.g. 'node').
   * @param string $bundle
   *   The bundle to filter by, or empty string for all bundles.
   * @param string $siteName
   *   The site name used in the ContentItem metadata.
   *
   * @return \Generator<\Tag1\Scolta\Export\ContentItem>
   *   Yields one ContentItem per published entity.
   *
   * @since 0.3.2
   * @stability experimental
   */
  public function gather(string $entityType, string $bundle, string $siteName): \Generator {
    $storage = $this->entityTypeManager->getStorage($entityType);
    $batch = 50;
    $offset = 0;

    while (TRUE) {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->range($offset, $batch)
        ->sort('nid', 'ASC');

      if ($bundle) {
        $bundleKey = $this->entityTypeManager->getDefinition($entityType)->getKey('bundle');
        if ($bundleKey) {
          $query->condition($bundleKey, $bundle);
        }
      }

      $ids = $query->execute();
      if (empty($ids)) {
        break;
      }

      $entities = $storage->loadMultiple($ids);

      foreach ($entities as $entity) {
        if (!$entity instanceof FieldableEntityInterface) {
          continue;
        }

        // Extract body content — try common field names.
        $body = '';
        foreach (['body', 'field_body', 'field_content'] as $field) {
          if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
            $body = $entity->get($field)->value;
            break;
          }
        }

        if (empty($body)) {
          continue;
        }

        $changedTime = $entity instanceof EntityChangedInterface
          ? $entity->getChangedTime()
          : (int) ($entity->get('changed')->value ?? 0);

        yield new ContentItem(
          id: (string) $entity->id(),
          title: $entity->label() ?: 'Untitled',
          bodyHtml: $body,
          url: $entity->toUrl()->setAbsolute(TRUE)->toString(),
          date: date('Y-m-d', $changedTime),
          siteName: $siteName,
        );
      }

      $storage->resetCache($ids);
      $offset += count($ids);
      unset($entities);
    }
  }

}
