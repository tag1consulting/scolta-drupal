<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\scolta\Plugin\QueueWorker\ScoltaRebuildWorker;

/**
 * Queues entities for reindexing without re-saving them.
 *
 * For when the indexed output is stale but the content is not: an alter hook
 * changed, a field mapping was added, a text format was rewritten. Saving
 * every entity would work but rewrites `changed` on content nobody edited —
 * and on a bundle with new revisions it also creates a revision per entity.
 *
 * `drush scolta:reindex` is one caller; any code that re-extracts derived
 * text (attachment text, a remote import) and needs Scolta to notice is
 * another.
 *
 * @since 2.0.0
 * @stability experimental
 */
class ScoltaReindexer {

  /**
   * Constructs a ScoltaReindexer object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\scolta\Service\ScoltaContentGatherer $contentGatherer
   *   The content gatherer service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ScoltaContentGatherer $contentGatherer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * Queue the given entities for a forced incremental reindex.
   *
   * The queued requests take the incremental update path, which merges into
   * the published index. They are marked forced, so the gather reloads every
   * entity and rewrites its timestamp manifest entry — without that the next
   * full build would find the untouched `changed` timestamp still matching
   * the manifest and serve the cached page again, silently undoing this.
   *
   * @param string $entityType
   *   The entity type ID the IDs belong to.
   * @param array $ids
   *   Entity IDs to reindex. Unpublished, missing, or non-indexed IDs are
   *   filtered out and reported in the return value's `skipped`. Duplicates
   *   are not: they are collapsed first, so a repeated ID is not reported as
   *   one that could not be loaded.
   *
   * @return array{entities: int, pages: int, skipped: int}
   *   The number of entities queued, the number of pages those entities
   *   contribute, and the number of IDs filtered out as unpublished, missing
   *   or non-indexed.
   *
   * @throws \RuntimeException
   *   If the target set exceeds `incremental.max_changed_items`, or if the
   *   queue backend rejected any request.
   *
   * @since 2.0.0
   * @stability experimental
   */
  public function queue(string $entityType, array $ids): array {
    // publishedIds() resolves the list through an IN query, which collapses
    // duplicates. Collapsing them here too keeps `skipped` a count of IDs
    // that could not be loaded rather than of IDs passed twice.
    $ids = array_values(array_unique(array_map('strval', $ids)));
    $targets = $this->contentGatherer->publishedIds($entityType, $ids);
    $skipped = count($ids) - count($targets);
    if ($targets === []) {
      return ['entities' => 0, 'pages' => 0, 'skipped' => $skipped];
    }

    // Refused rather than queued: a change set over the threshold falls back
    // to a full rebuild, and a full rebuild reads the timestamp manifest, so
    // it would serve the very cached pages this exists to refresh. Each
    // entity contributes at least one page, so the ID count is a cheap lower
    // bound; the exact page count is checked again below.
    $threshold = (int) ($this->configFactory->get('scolta.settings')->get('incremental.max_changed_items')
      ?? ScoltaRebuildWorker::DEFAULT_MAX_INCREMENTAL_ITEMS);
    $refuse = function (int $count) use ($threshold): void {
      throw new \RuntimeException(sprintf(
        "%d pages exceeds the incremental threshold of %d, and a change set that large falls back to a\n"
        . "full rebuild — which reads the timestamp manifest and would serve exactly the cached pages\n"
        . "you are trying to refresh.\n\n"
        . "Rebuild the whole index instead:\n"
        . "  drush scolta:build --force\n\n"
        . 'Or reindex in smaller slices with --ids.',
        $count,
        $threshold,
      ));
    };
    if ($threshold > 0 && count($targets) > $threshold) {
      $refuse(count($targets));
    }

    $storage = $this->entityTypeManager->getStorage($entityType);
    $payloads = [];
    $pages = 0;
    foreach (array_chunk($targets, 50) as $chunk) {
      // Loaded, never saved: a save would move `changed` on content nobody
      // edited, which is the whole reason this exists.
      foreach ($storage->loadMultiple($chunk) as $entity) {
        $itemIds = $this->contentGatherer->itemIdsFor($entity);
        if ($itemIds === []) {
          continue;
        }
        $pages += count($itemIds);
        $payloads[] = [
          'type' => 'reindex',
          'op' => 'update',
          'entity_type' => $entityType,
          'entity_id' => $entity->id(),
          'item_ids' => $itemIds,
          'force' => TRUE,
        ];
      }
    }

    if ($threshold > 0 && $pages > $threshold) {
      $refuse($pages);
    }

    $queue = $this->queueFactory->get(ScoltaRebuildWorker::QUEUE_NAME);
    $queued = 0;
    foreach ($payloads as $payload) {
      // DatabaseQueue::createItem() returns FALSE rather than throwing when
      // the insert fails, so an unchecked loop can report a queued reindex
      // that queued nothing.
      if ($queue->createItem($payload) !== FALSE) {
        $queued++;
      }
    }
    if ($queued !== count($payloads)) {
      throw new \RuntimeException(sprintf(
        'Only %d of %d reindex requests could be queued. The queue backend rejected the rest; nothing further was attempted.',
        $queued,
        count($payloads),
      ));
    }

    // Deliberately does not set scolta.rebuild_requested_at: that key debounces
    // bursts of content edits, and this is not a content edit — setting it
    // would make the caller wait out a delay they did not cause.
    return ['entities' => $queued, 'pages' => $pages, 'skipped' => $skipped];
  }

}
