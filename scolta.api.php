<?php

/**
 * @file
 * Hooks provided by the Scolta module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter a ContentItem before it is yielded for indexing.
 *
 * Invoked in ScoltaContentGatherer::gather() after each ContentItem is
 * constructed from an entity translation. Config-driven field mappings
 * (see field_mappings in scolta.settings) are applied before this hook,
 * so implementations can read, override, or extend auto-mapped values.
 *
 * The $item parameter is a ContentItem object. Because ContentItem is a
 * readonly class, use $item->cloneWith(['sortable' => [...]]) to return a
 * modified copy and reassign it to $item.
 *
 * @param \Tag1\Scolta\Export\ContentItem $item
 *   The ContentItem to be indexed. Reassign to modify.
 * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
 *   The entity translation that was used to build the ContentItem.
 *
 * @see \Drupal\scolta\Service\ScoltaContentGatherer::gather()
 */
function hook_scolta_content_item_alter(\Tag1\Scolta\Export\ContentItem &$item, \Drupal\Core\Entity\FieldableEntityInterface $entity): void {
  // Example: Add a custom sortable field from a node's integer field.
  if ($entity->bundle() === 'article' && $entity->hasField('field_priority')) {
    $sortable = $item->sortable;
    $sortable['priority'] = (int) $entity->get('field_priority')->value;
    $item = $item->cloneWith(['sortable' => $sortable]);
  }

  // Example: Add a filter dimension from a taxonomy reference field.
  if ($entity->hasField('field_category') && !$entity->get('field_category')->isEmpty()) {
    $filters = $item->filters;
    $term = $entity->get('field_category')->entity;
    if ($term) {
      $filters['category'] = $term->label();
    }
    $item = $item->cloneWith(['filters' => $filters]);
  }

  // Note: For simple field-to-dimension mappings like the examples above,
  // prefer the field_mappings config (admin UI or scolta.settings.yml).
  // Use this hook for complex logic: conditional mappings, computed values,
  // cross-field calculations, or transformations the config mapper
  // cannot express.
}

/**
 * @} End of "addtogroup hooks".
 */
