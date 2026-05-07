<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\text\Plugin\Field\FieldType\TextItemBase;
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
   * Paginates the entity query in batches of 100. Each entity is freed via
   * array_shift immediately after its ContentItem(s) are yielded — the
   * generator does not hold the full batch in scope across yields. After each
   * batch, resetCache(), drupal_static_reset(), and gc_collect_cycles() are
   * called to release Drupal's accumulated per-request static caches (URL
   * aliases, typed data instances, access results, etc.). Peak RSS stays
   * bounded regardless of corpus size.
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
    // 10 entities per load keeps the per-batch memory spike to ~2.5 MB.
    // 100 caused 25+ MB spikes on large-article corpora (e.g. Wikipedia) that
    // PHP's allocator never returns, leading to monotonic heap growth.
    $batch = 10;
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

      // Process one entity at a time using array_shift so each entity object
      // is released from $entities before we yield — the generator pauses at
      // every yield, which would otherwise keep all 100 loaded entities alive
      // in the generator's stack frame simultaneously. For large articles
      // (e.g. Wikipedia with 2–5 MB of body HTML per node) this was the
      // primary source of memory exhaustion on large corpora.
      while ($entities) {
        $entity = array_shift($entities);

        if (!$entity instanceof FieldableEntityInterface) {
          unset($entity);
          continue;
        }

        // Yield every available translation as a separate indexed page.
        foreach ($entity->getTranslationLanguages() as $langcode => $language) {
          $translation = $entity->getTranslation($langcode);

          // Extract body content — try common field names.
          $body = '';
          foreach (['body', 'field_body', 'field_content'] as $field) {
            if ($translation->hasField($field) && !$translation->get($field)->isEmpty()) {
              $item = $translation->get($field)->first();
              if ($item instanceof TextItemBase) {
                // ->processed runs text format filters; strip_tags gives clean text.
                // Cast to string: ->processed returns FilteredMarkup, not a plain string.
                // Fall back to ->value if the text format is misconfigured.
                $body = strip_tags((string) $item->processed) ?: strip_tags((string) $item->value);
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

          $changedTime = $translation instanceof EntityChangedInterface
            ? $translation->getChangedTime()
            : (int) ($translation->get('changed')->value ?? 0);

          // Single-language entities and English translations keep plain IDs
          // for backward compatibility. Other languages get a -{langcode} suffix
          // to avoid filename collisions when the same entity has multiple
          // translations (e.g. node/42 → "42" for en, "42-es" for es).
          $languages = $entity->getTranslationLanguages();
          $itemId = ($langcode === 'en' || count($languages) === 1)
            ? (string) $entity->id()
            : $entity->id() . '-' . $langcode;

          yield new ContentItem(
            id: $itemId,
            title: $translation->label() ?: 'Untitled',
            bodyHtml: $body,
            url: $translation->toUrl()->setAbsolute(TRUE)->toString(),
            date: date('Y-m-d', $changedTime),
            siteName: $siteName,
            language: $langcode,
          );
        }

        unset($entity);
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

}
