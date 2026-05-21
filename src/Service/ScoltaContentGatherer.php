<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\text\Plugin\Field\FieldType\TextItemBase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\CachedContentReference;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\TimestampManifest;

/**
 * Central content gathering service.
 *
 * Single source of truth for collecting indexable content across entity types.
 * Both the Drush command pipeline (PHP indexer) and the legacy HTML-export
 * pipeline delegate to this class so the query logic lives in one place.
 *
 * When a TimestampManifest is passed to gather(), entities whose changed
 * timestamp has not changed since the last build are yielded as
 * CachedContentReference objects instead of fully-loaded ContentItems. The
 * IndexBuildOrchestrator handles both types: it re-uses cached token data for
 * references and tokenizes from scratch for ContentItems. On a 44k-page site
 * with 10 changed pages this reduces rebuild time from minutes to seconds
 * because loadMultiple() is skipped for the 43,990 unchanged entities.
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
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection (used for lightweight timestamp queries).
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler (used to invoke hook_scolta_content_item_alter).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (used to read field_mappings).
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
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
   * Return changed timestamps for a batch of entity IDs without loading them.
   *
   * Runs a direct query against the entity's data table to retrieve only the
   * primary-key and 'changed' columns. This is O(batch) in DB round-trips and
   * O(1) in PHP memory compared to a full loadMultiple(). Entities whose type
   * does not have a 'changed' column return 0 as a safe default that forces a
   * full re-index.
   *
   * @param string $entityType
   *   The entity type identifier (e.g. 'node').
   * @param int[] $ids
   *   Entity IDs to look up.
   *
   * @return array<int, int>
   *   Map of entity ID → changed UNIX timestamp (0 on error or missing column).
   *
   * @since 0.3.12
   * @stability experimental
   */
  public function getEntityTimestamps(string $entityType, array $ids): array {
    if (empty($ids)) {
      return [];
    }

    try {
      $def = $this->entityTypeManager->getDefinition($entityType);
      $dataTable = $def->getDataTable() ?? $def->getBaseTable();
      if (!$dataTable) {
        return [];
      }
      $idKey = $def->getKey('id');

      return $this->database->select($dataTable, 'e')
        ->fields('e', [$idKey, 'changed'])
        ->condition($idKey, $ids, 'IN')
        ->execute()
        ->fetchAllKeyed(0, 1);
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Gather indexable content as a generator that yields one item at a time.
   *
   * Paginates the entity query in batches of 10. When a TimestampManifest is
   * provided and $force is false, entities whose changed timestamp matches the
   * manifest are yielded as CachedContentReference objects without loading their
   * body content. Changed or new entities are fully loaded and yielded as
   * ContentItem objects; the manifest is updated with their new timestamp and
   * pre-computed content hash.
   *
   * After each batch, resetCache(), drupal_static_reset(), and gc_collect_cycles()
   * are called to release Drupal's accumulated per-request static caches. Peak
   * RSS stays bounded regardless of corpus size.
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
   * @param int $startPage
   *   Number of entities to skip before yielding. Used on --resume to restart
   *   the DB cursor at the previously processed offset rather than page 0.
   * @param \Tag1\Scolta\Index\TimestampManifest|null $manifest
   *   When provided, entities with matching timestamps are yielded as
   *   CachedContentReference objects instead of loading their full body.
   * @param bool $force
   *   When true, ignore the manifest and fully load every entity.
   *
   * @return \Generator<\Tag1\Scolta\Export\ContentItem|\Tag1\Scolta\Index\CachedContentReference>
   *   Yields one ContentItem or CachedContentReference per published entity.
   *
   * @since 0.3.2
   * @stability experimental
   */
  public function gather(string $entityType, string $bundle, string $siteName, int $startPage = 0, ?TimestampManifest $manifest = NULL, bool $force = FALSE): \Generator {
    $storage = $this->entityTypeManager->getStorage($entityType);
    // 10 entities per load keeps the per-batch memory spike to ~2.5 MB.
    // 100 caused 25+ MB spikes on large-article corpora (e.g. Wikipedia) that
    // PHP's allocator never returns, leading to monotonic heap growth.
    $batch = 10;
    $offset = $startPage;

    $idKey = $this->entityTypeManager->getDefinition($entityType)->getKey('id');

    while (TRUE) {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->range($offset, $batch)
        ->sort($idKey, 'ASC');

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

      // Timestamp check: determine which entities need to be fully loaded.
      $timestamps = [];
      $toLoad = [];
      if ($manifest !== NULL && !$force) {
        $timestamps = $this->getEntityTimestamps($entityType, array_values($ids));
        foreach ($ids as $id) {
          $entityKey = (string) $id;
          $entry = $manifest->get($entityKey);
          if ($entry !== NULL && ((int) ($timestamps[$id] ?? 0)) === $entry['ts']) {
            // Entity unchanged — yield cached references, skip full entity load.
            foreach ($entry['items'] as $itemData) {
              yield new CachedContentReference(
                entityKey: $entityKey,
                contentHash: $itemData['hash'],
                id: $itemData['id'],
                url: $itemData['url'],
                date: $itemData['date'],
                siteName: $itemData['siteName'],
                language: $itemData['language'],
                filters: $itemData['filters'] ?? [],
                sortable: $itemData['sortable'] ?? [],
              );
            }
          }
          else {
            $toLoad[] = $id;
          }
        }
      }
      else {
        $toLoad = array_values($ids);
      }

      if (!empty($toLoad)) {
        $entities = $storage->loadMultiple($toLoad);

        // Process one entity at a time using array_shift so each entity object
        // is released from $entities before we yield — the generator pauses at
        // every yield, which would otherwise keep all loaded entities alive
        // in the generator's stack frame simultaneously.
        while ($entities) {
          $entity = array_shift($entities);

          if (!$entity instanceof FieldableEntityInterface) {
            unset($entity);
            continue;
          }

          $entityKey = (string) $entity->id();
          $entityTs = (int) ($timestamps[$entity->id()] ?? 0);
          $itemsForManifest = [];

          // Yield every available translation as a separate indexed page.
          foreach ($entity->getTranslationLanguages() as $langcode => $language) {
            $translation = $entity->getTranslation($langcode);

            // Extract body content — try common field names.
            $body = '';
            foreach (['body', 'field_body', 'field_content'] as $field) {
              if ($translation->hasField($field) && !$translation->get($field)->isEmpty()) {
                $item = $translation->get($field)->first();
                if ($item instanceof TextItemBase) {
                  // ->processed runs text format filters; PlainTextOutput decodes HTML entities.
                  // Cast to string: ->processed returns FilteredMarkup, not a plain string.
                  // Fall back to ->value if the text format is misconfigured.
                  $body = PlainTextOutput::renderFromHtml((string) $item->processed) ?: PlainTextOutput::renderFromHtml((string) $item->value);
                }
                else {
                  $body = $item->value;
                }
                break;
              }
            }

            if (empty($body)) {
              continue;
            }

            if ($entityTs === 0) {
              $entityTs = $translation instanceof EntityChangedInterface
                ? $translation->getChangedTime()
                : (int) ($translation->get('changed')->value ?? 0);
            }

            // Single-language entities and English translations keep plain IDs
            // for backward compatibility. Other languages get a -{langcode} suffix
            // to avoid filename collisions when the same entity has multiple
            // translations (e.g. node/42 → "42" for en, "42-es" for es).
            $languages = $entity->getTranslationLanguages();
            $itemId = ($langcode === 'en' || count($languages) === 1)
              ? (string) $entity->id()
              : $entity->id() . '-' . $langcode;

            $contentItem = new ContentItem(
              id: $itemId,
              title: $translation->label() ?: 'Untitled',
              bodyHtml: $body,
              url: $translation->toUrl()->toString(),
              date: date('Y-m-d', $entityTs),
              siteName: $siteName,
              language: $langcode,
            );

            $fieldMappings = $this->configFactory->get('scolta.settings')->get('field_mappings') ?? [];
            $autoSortable = $contentItem->sortable;
            $autoFilters = $contentItem->filters;

            foreach ($fieldMappings['sortable'] ?? [] as $fieldName => $dimension) {
              if ($translation->hasField($fieldName) && !$translation->get($fieldName)->isEmpty()) {
                $value = $this->resolveFieldValue($translation->get($fieldName));
                if ($value !== NULL) {
                  $autoSortable[$dimension] = $value;
                }
              }
            }

            foreach ($fieldMappings['filters'] ?? [] as $fieldName => $dimension) {
              if ($translation->hasField($fieldName) && !$translation->get($fieldName)->isEmpty()) {
                $value = $this->resolveFieldValue($translation->get($fieldName));
                if ($value !== NULL) {
                  $autoFilters[$dimension] = $value;
                }
              }
            }

            if ($autoSortable !== $contentItem->sortable || $autoFilters !== $contentItem->filters) {
              $contentItem = $contentItem->cloneWith([
                'sortable' => $autoSortable,
                'filters' => $autoFilters,
              ]);
            }

            $this->moduleHandler->alter('scolta_content_item', $contentItem, $translation);

            if ($manifest !== NULL && !$force) {
              $hash = PhpIndexer::contentHash($contentItem);
              $itemsForManifest[] = [
                'hash'     => $hash,
                'id'       => $contentItem->id,
                'url'      => $contentItem->url,
                'date'     => $contentItem->date,
                'siteName' => $contentItem->siteName,
                'language' => $contentItem->language,
                'filters'  => $contentItem->filters,
                'sortable' => $contentItem->sortable,
              ];
            }

            yield $contentItem;
          }

          if ($manifest !== NULL && !$force && !empty($itemsForManifest)) {
            $manifest->put($entityKey, $entityTs, $itemsForManifest);
          }

          unset($entity);
        }
      }

      $storage->resetCache($ids);
      $offset += count($ids);
      // Clear Drupal's per-request static caches (URL aliases, access results,
      // typed data instances, etc.) that accumulate across entity batches and
      // are never automatically reset during a long-running CLI build.
      drupal_static_reset();
      gc_collect_cycles();
    }
  }

  /**
   * Extract a scalar value from a field item list for indexing.
   *
   * @return string|int|float|null
   *   The resolved value, or NULL if the field cannot be resolved.
   */
  private function resolveFieldValue(FieldItemListInterface $fieldItemList): string|int|float|null {
    $fieldType = $fieldItemList->getFieldDefinition()->getType();

    if (in_array($fieldType, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      $labels = [];
      foreach ($fieldItemList as $item) {
        if ($item->entity) {
          $labels[] = $item->entity->label();
        }
      }
      if (empty($labels)) {
        return NULL;
      }
      return count($labels) === 1 ? $labels[0] : implode(', ', $labels);
    }

    if (in_array($fieldType, ['integer', 'decimal', 'float'], TRUE)) {
      $value = $fieldItemList->first()?->value;
      if ($value === NULL) {
        return NULL;
      }
      return $fieldType === 'integer' ? (int) $value : (float) $value;
    }

    $value = $fieldItemList->first()?->value;
    return $value !== NULL ? (string) $value : NULL;
  }

}
