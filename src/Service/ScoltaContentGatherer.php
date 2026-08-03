<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
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
 * @since 1.0.0-rc1
 * @stability experimental
 */
class ScoltaContentGatherer {

  /**
   * Entities loaded per loadMultiple() call.
   *
   * 10 entities per load keeps the per-batch memory spike to ~2.5 MB. 100
   * caused 25+ MB spikes on large-article corpora (e.g. Wikipedia) that PHP's
   * allocator never returns, leading to monotonic heap growth. This is a
   * memory bound and is deliberately NOT the same number as ID_PAGE_SIZE.
   */
  private const LOAD_BATCH_SIZE = 10;

  /**
   * Entity IDs fetched per entity query.
   *
   * The cost of an entity query is per call, not per row: on a 124k-row corpus
   * asking for 1 ID measured 56.1 ms and asking for 500 measured 56.0 ms, and
   * the equivalent raw SELECT ran in 0.03 ms, so essentially all of it is
   * fixed overhead in the entity query layer rather than database work. Paging
   * IDs at the load batch size therefore paid that fixed cost 12,397 times for
   * a 124k-row walk. Fetching IDs in large pages and still loading entities
   * LOAD_BATCH_SIZE at a time separates the two concerns: query count drops by
   * this factor while the memory profile of a load is untouched.
   */
  private const ID_PAGE_SIZE = 200;

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
   * @since 1.0.0-rc1
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
   * @since 1.0.0-rc1
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
   * Walks the corpus with a keyset cursor: each page selects the rows whose ID
   * is greater than the last ID seen. IDs are fetched ID_PAGE_SIZE at a time
   * because an entity query costs the same whether it returns one row or five
   * hundred, while entities are still loaded LOAD_BATCH_SIZE at a time because
   * that is a memory bound. When a TimestampManifest is provided and
   * $force is false, entities whose changed timestamp matches the manifest are
   * yielded as CachedContentReference objects without loading body content.
   * Changed or new entities are fully loaded and yielded as ContentItem
   * objects; the manifest is updated with their new timestamp and
   * pre-computed content hash.
   *
   * After each batch that loaded an entity, resetCache(),
   * drupal_static_reset(), and gc_collect_cycles() are called to release
   * Drupal's accumulated per-request static caches. Peak RSS stays bounded
   * regardless of corpus size. A batch the manifest answered in full loaded
   * nothing and has nothing to release, so it skips them.
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
   * @param int|string|null $resumeFromId
   *   Restart the walk at this entity ID, inclusive, rather than at the first
   *   row. Used by --resume. It is an ID and not a row offset because those
   *   are different units: the build manifest counts pages, this cursor walks
   *   entities, and an entity yields one page per translation — so passing a
   *   page count here skipped the wrong rows by the translation factor, and
   *   on a monolingual corpus (where the two happen to agree) skipped rows
   *   whose pages were then renumbered. Inclusive because the entity at the
   *   boundary may have had only some of its translations committed; the
   *   caller drops the ones it already has.
   * @param \Tag1\Scolta\Index\TimestampManifest|null $manifest
   *   When provided, entities with matching timestamps are yielded as
   *   CachedContentReference objects instead of loading their full body.
   * @param bool $force
   *   When true, ignore the manifest and fully load every entity.
   *
   * @return \Generator<\Tag1\Scolta\Export\ContentItem|\Tag1\Scolta\Index\CachedContentReference>
   *   Yields one ContentItem or CachedContentReference per published entity.
   *
   * @since 1.0.0-rc1
   * @stability experimental
   */
  public function gather(string $entityType, string $bundle, string $siteName, int|string|NULL $resumeFromId = NULL, ?TimestampManifest $manifest = NULL, bool $force = FALSE): \Generator {
    $storage = $this->entityTypeManager->getStorage($entityType);

    $idKey = $this->entityTypeManager->getDefinition($entityType)->getKey('id');
    $bundleKey = $bundle
      ? $this->entityTypeManager->getDefinition($entityType)->getKey('bundle')
      : NULL;

    // Keyset pagination: each page asks for the rows after the last ID seen
    // rather than skipping a growing offset. Measured on a 124k-row corpus the
    // deep-offset penalty was mild (55.7 ms at offset 0 against 59.2 ms at
    // offset 80,000), so this is not the O(m) fix it looks like. It pays for a
    // different reason: bounding the walk with `id > n` lets the planner range
    // scan the primary key, which measured 26.4 ms against 59.2 ms for the
    // equivalent offset query. The ascending-ID contract callers rely on is
    // unchanged; only the cursor's expression differs.
    $lastId = NULL;
    // The resume boundary is inclusive, so it seeds the first query with `>=`
    // and every later one advances with `>` as usual.
    $resumeBoundary = $resumeFromId !== NULL && $resumeFromId !== '' ? $resumeFromId : NULL;

    while (TRUE) {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->range(0, self::ID_PAGE_SIZE)
        ->sort($idKey, 'ASC');

      if ($lastId !== NULL) {
        $query->condition($idKey, $lastId, '>');
      }
      elseif ($resumeBoundary !== NULL) {
        $query->condition($idKey, $resumeBoundary, '>=');
      }

      if ($bundleKey) {
        $query->condition($bundleKey, $bundle);
      }

      $idPage = $query->execute();
      if (empty($idPage)) {
        break;
      }

      // The query sorts ascending, so the last value is the high-water mark
      // this page advances the cursor to.
      $lastId = end($idPage);
      reset($idPage);

      // One timestamp lookup per ID page rather than per load batch: it is a
      // single direct SELECT over the data table and answers the skip question
      // for every row in the page at once.
      $timestamps = ($manifest !== NULL && !$force)
        ? $this->getEntityTimestamps($entityType, array_values($idPage))
        : [];

      foreach (array_chunk($idPage, self::LOAD_BATCH_SIZE, TRUE) as $ids) {
        $toLoad = [];
        if ($manifest !== NULL && !$force) {
          foreach ($ids as $id) {
            $entityKey = (string) $id;
            $entry = $manifest->get($entityKey);
            if ($this->manifestEntryIsFresh($entry, (int) ($timestamps[$id] ?? 0))) {
              // Entity unchanged — yield cached references, skip the full
              // entity load.
              foreach ($entry['items'] as $itemData) {
                yield $this->cachedReference($entityKey, $itemData);
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

        $loadedAnything = !empty($toLoad);

        if ($loadedAnything) {
          $entities = $storage->loadMultiple($toLoad);

          // Process one entity at a time using array_shift so each entity
          // object is released from $entities before we yield — the generator
          // pauses at every yield, which would otherwise keep all loaded
          // entities alive in the generator's stack frame simultaneously.
          while ($entities) {
            $entity = array_shift($entities);

            if (!$entity instanceof FieldableEntityInterface) {
              unset($entity);
              continue;
            }

            $entityKey = (string) $entity->id();
            $entityTs = (int) ($timestamps[$entity->id()] ?? 0);
            $itemsForManifest = [];

            [$contentItems, $entityTs] = $this->buildContentItems($entity, $siteName, $entityTs);

            foreach ($contentItems as $contentItem) {
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
                  'metadata' => $contentItem->metadata,
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

        $this->releaseBatch($storage, $ids, $loadedAnything);
      }
    }
  }

  /**
   * Release the per-request statics a batch accumulated.
   *
   * Drupal never resets its per-request static caches during a long CLI run,
   * so URL aliases, access results and typed-data instances would grow for the
   * whole build. The reset is therefore not optional when entities were
   * loaded.
   *
   * It is skippable when nothing was loaded. On a warm build the timestamp
   * manifest answers most batches from its own records without touching entity
   * storage, so there is no new entity static to release, and the reset plus
   * collection is pure overhead repeated once per batch — thousands of times
   * per build. Only the batches that actually loaded something pay for it.
   *
   * @param mixed $storage
   *   The entity storage handler.
   * @param array $ids
   *   The IDs in this batch.
   * @param bool $loadedAnything
   *   Whether this batch loaded any entity.
   */
  private function releaseBatch($storage, array $ids, bool $loadedAnything): void {
    if (!$loadedAnything) {
      return;
    }

    $storage->resetCache($ids);
    // Clear Drupal's per-request static caches (URL aliases, access results,
    // typed data instances, etc.) that accumulate across entity batches and
    // are never automatically reset during a long-running CLI build.
    drupal_static_reset();
    gc_collect_cycles();
  }

  /**
   * Whether a manifest record still matches the entity's current timestamp.
   *
   * @param array|null $entry
   *   The manifest record, or NULL when the entity is new to the manifest.
   * @param int $currentTs
   *   The entity's current changed timestamp.
   */
  private function manifestEntryIsFresh(?array $entry, int $currentTs): bool {
    if ($entry === NULL || $currentTs !== $entry['ts']) {
      return FALSE;
    }

    // A record written before metadata joined the manifest would yield cached
    // references with an empty metadata array, silently dropping whatever
    // hook_scolta_content_item_alter() put there. Treat it as changed so the
    // entity is reloaded once and the record is rewritten with metadata.
    // array_key_exists(), not isset() or a falsy check: an item whose metadata
    // is legitimately empty must not be reloaded on every build forever.
    foreach ($entry['items'] as $itemData) {
      if (!array_key_exists('metadata', $itemData)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Build a CachedContentReference from one manifest item record.
   */
  private function cachedReference(string $entityKey, array $itemData): CachedContentReference {
    return new CachedContentReference(
      entityKey: $entityKey,
      contentHash: $itemData['hash'],
      id: $itemData['id'],
      url: $itemData['url'],
      date: $itemData['date'],
      siteName: $itemData['siteName'],
      language: $itemData['language'],
      filters: $itemData['filters'] ?? [],
      sortable: $itemData['sortable'] ?? [],
      metadata: $itemData['metadata'] ?? [],
    );
  }

  /**
   * Gather ContentItems for an explicit list of entity IDs.
   *
   * Used by Batch API steps that receive pre-computed ID slices: every item
   * goes through the exact same per-entity pipeline as gather() — text-format
   * rendering, translations, field mappings, and the
   * hook_scolta_content_item_alter() hook — so batch-built indexes match
   * Drush-built ones.
   *
   * @param string $entityType
   *   The entity type to load (e.g. 'node').
   * @param int[] $ids
   *   Entity IDs to load and convert.
   * @param string $siteName
   *   The site name used in the ContentItem metadata.
   * @param \Tag1\Scolta\Index\TimestampManifest|null $manifest
   *   When provided, entities whose changed timestamp still matches the
   *   manifest are yielded as CachedContentReference objects instead of being
   *   loaded, exactly as in gather(). Optional and last so existing callers
   *   keep working unchanged.
   * @param bool $force
   *   When true, ignore the manifest and fully load every entity.
   *
   * @return \Generator<\Tag1\Scolta\Export\ContentItem|\Tag1\Scolta\Index\CachedContentReference>
   *   Yields one ContentItem or CachedContentReference per published
   *   translation.
   *
   * @since 1.0.4
   * @stability experimental
   */
  public function gatherByIds(string $entityType, array $ids, string $siteName, ?TimestampManifest $manifest = NULL, bool $force = FALSE): \Generator {
    $storage = $this->entityTypeManager->getStorage($entityType);

    foreach (array_chunk($ids, 10) as $chunk) {
      $timestamps = [];
      $toLoad = $chunk;

      if ($manifest !== NULL && !$force) {
        $timestamps = $this->getEntityTimestamps($entityType, array_values($chunk));
        $toLoad = [];
        foreach ($chunk as $id) {
          $entityKey = (string) $id;
          $entry = $manifest->get($entityKey);
          if ($this->manifestEntryIsFresh($entry, (int) ($timestamps[$id] ?? 0))) {
            foreach ($entry['items'] as $itemData) {
              yield $this->cachedReference($entityKey, $itemData);
            }
          }
          else {
            $toLoad[] = $id;
          }
        }
      }

      $loadedAnything = !empty($toLoad);
      $entities = $loadedAnything ? $storage->loadMultiple($toLoad) : [];

      while ($entities) {
        $entity = array_shift($entities);
        if (!$entity instanceof FieldableEntityInterface) {
          unset($entity);
          continue;
        }

        $entityKey = (string) $entity->id();
        $entityTs = (int) ($timestamps[$entity->id()] ?? 0);
        $itemsForManifest = [];

        [$contentItems, $entityTs] = $this->buildContentItems($entity, $siteName, $entityTs);
        foreach ($contentItems as $contentItem) {
          if ($manifest !== NULL && !$force) {
            $itemsForManifest[] = [
              'hash'     => PhpIndexer::contentHash($contentItem),
              'id'       => $contentItem->id,
              'url'      => $contentItem->url,
              'date'     => $contentItem->date,
              'siteName' => $contentItem->siteName,
              'language' => $contentItem->language,
              'filters'  => $contentItem->filters,
              'sortable' => $contentItem->sortable,
              'metadata' => $contentItem->metadata,
            ];
          }

          yield $contentItem;
        }

        if ($manifest !== NULL && !$force && !empty($itemsForManifest)) {
          $manifest->put($entityKey, $entityTs, $itemsForManifest);
        }

        unset($entity);
      }

      $this->releaseBatch($storage, $chunk, $loadedAnything);
    }
  }

  /**
   * The content item IDs the gatherer would produce for an entity.
   *
   * One ID per translation, using the same rule as the gathering pipeline.
   * The queue payload carries these so the worker can stage exactly the pages
   * an edit touched, and the page-table ledger keys its ordinals by the very
   * same strings — a second copy of this rule anywhere else would orphan
   * pages the moment the two drifted, so callers must use this method rather
   * than rebuilding the ID by hand.
   *
   * Every translation is listed regardless of whether it currently has body
   * content. A translation whose body was just emptied yields no ContentItem,
   * and the caller needs the difference between "expected" and "produced" to
   * know that its page has to be removed from the index.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to derive IDs for.
   *
   * @return string[]
   *   The content item IDs, or an empty array for a non-fieldable entity.
   *
   * @since 1.2.0
   * @stability experimental
   */
  public function itemIdsFor(EntityInterface $entity): array {
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }

    $languages = $entity->getTranslationLanguages();
    $count = count($languages);

    $itemIds = [];
    foreach (array_keys($languages) as $langcode) {
      $itemIds[] = self::itemId((string) $entity->id(), (string) $langcode, $count);
    }

    return $itemIds;
  }

  /**
   * Filter a list of entity IDs down to the ones that are still published.
   *
   * Only published entities are ever yielded by gather(). A caller working
   * from an explicit ID list — a queue payload, a batch slice — has no such
   * filter, and an unpublish arrives as an ordinary update, so without this
   * the unpublished node is re-gathered and stays in the index.
   *
   * @param string $entityType
   *   The entity type to query (e.g. 'node').
   * @param array $ids
   *   Candidate entity IDs.
   *
   * @return array
   *   The subset of $ids that is published, in ascending ID order.
   *
   * @since 1.2.0
   * @stability experimental
   */
  public function publishedIds(string $entityType, array $ids): array {
    if (empty($ids)) {
      return [];
    }

    $definition = $this->entityTypeManager->getDefinition($entityType);
    $idKey = $definition->getKey('id');

    $query = $this->entityTypeManager->getStorage($entityType)->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition($idKey, array_values($ids), 'IN')
      ->sort($idKey, 'ASC');

    return array_values($query->execute());
  }

  /**
   * The indexed page ID for one translation of an entity.
   *
   * Single-language entities and English translations keep plain IDs for
   * backward compatibility. Other languages get a -{langcode} suffix to avoid
   * filename collisions when the same entity has multiple translations
   * (e.g. node/42 → "42" for en, "42-es" for es).
   */
  private static function itemId(string $entityId, string $langcode, int $translationCount): string {
    return ($langcode === 'en' || $translationCount === 1)
      ? $entityId
      : $entityId . '-' . $langcode;
  }

  /**
   * Build the ContentItems for one entity — the single conversion pipeline.
   *
   * Yields every translation as a separate indexed page, renders body text
   * through the field's text format (->processed), applies the configured
   * field_mappings, and invokes hook_scolta_content_item_alter(). Every
   * gathering entry point (gather(), gatherByIds()) funnels through here.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The loaded entity.
   * @param string $siteName
   *   The site name used in the ContentItem metadata.
   * @param int $entityTs
   *   Known changed timestamp, or 0 to derive it from the entity.
   *
   * @return array{0: \Tag1\Scolta\Export\ContentItem[], 1: int}
   *   The content items for all translations, and the resolved timestamp.
   */
  private function buildContentItems(FieldableEntityInterface $entity, string $siteName, int $entityTs): array {
    $items = [];

    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      $translation = $entity->getTranslation($langcode);

      // Extract body content — try common field names.
      $body = '';
      foreach (['body', 'field_body', 'field_content'] as $field) {
        if ($translation->hasField($field) && !$translation->get($field)->isEmpty()) {
          $item = $translation->get($field)->first();
          if ($item instanceof TextItemBase) {
            // ->processed runs text format filters; PlainTextOutput
            // decodes HTML entities. Cast to string: ->processed
            // returns FilteredMarkup, not a plain string.
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

      $languages = $entity->getTranslationLanguages();
      $itemId = self::itemId((string) $entity->id(), (string) $langcode, count($languages));

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

      $items[] = $contentItem;
    }

    return [$items, $entityTs];
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
