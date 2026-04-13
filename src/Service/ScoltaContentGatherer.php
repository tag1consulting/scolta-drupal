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
   * Gather all indexable content items from a Drupal entity type.
   *
   * Queries published entities, extracts body content from common field
   * names, and returns an array of ContentItem DTOs ready for indexing.
   *
   * @param string $entityType
   *   The entity type to query (e.g. 'node').
   * @param string $bundle
   *   The bundle to filter by, or empty string for all bundles.
   * @param string $siteName
   *   The site name used in the ContentItem metadata.
   *
   * @return \Tag1\Scolta\Export\ContentItem[]
   *   Array of content items. Empty if no matching entities found.
   *
   * @since 0.2.0
   * @stability experimental
   */
  public function gather(string $entityType, string $bundle, string $siteName): array {
    $storage = $this->entityTypeManager->getStorage($entityType);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1);

    if ($bundle) {
      $bundleKey = $this->entityTypeManager->getDefinition($entityType)->getKey('bundle');
      if ($bundleKey) {
        $query->condition($bundleKey, $bundle);
      }
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $entities = $storage->loadMultiple($ids);
    $items = [];

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

      $items[] = new ContentItem(
        id: (string) $entity->id(),
        title: $entity->label() ?: 'Untitled',
        bodyHtml: $body,
        url: $entity->toUrl()->setAbsolute(TRUE)->toString(),
        date: date('Y-m-d', $changedTime),
        siteName: $siteName,
      );
    }

    return $items;
  }

}
