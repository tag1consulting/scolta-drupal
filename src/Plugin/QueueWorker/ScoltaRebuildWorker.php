<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\QueueWorker;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Progress\LockRenewingProgressReporter;
use Drupal\scolta\Service\ScoltaContentGatherer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\IncrementalIndexUpdater;
use Tag1\Scolta\Index\IncrementalUpdateUnavailable;
use Tag1\Scolta\Index\IndexBuildOrchestrator;

/**
 * Queue worker for rebuilding the Scolta search index.
 *
 * Processes queued rebuild requests triggered by entity changes when
 * auto-rebuild is enabled. Runs the same streamed pipeline as
 * `drush scolta:build`: ScoltaContentGatherer (translations, text-format
 * rendering, field mappings, alter hook; 10 entities per load) →
 * ContentExporter::filterItems() → IndexBuildOrchestrator.
 *
 * Rebuilds are debounced: scolta.module records the last content change in
 * the scolta.rebuild_requested_at state key, and the worker suspends the
 * queue until the backend's auto_rebuild_delay has elapsed since that
 * change, so a burst of edits produces one build. After a successful build
 * the remaining queued duplicates are drained.
 *
 * @QueueWorker(
 *   id = "scolta_rebuild",
 *   title = @Translation("Scolta Index Rebuild"),
 *   cron = {"time" = 120}
 * )
 *
 * @since 1.0.0-rc1
 * @stability experimental
 */
class ScoltaRebuildWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Fallback debounce delay when no Scolta search_api server exists.
   */
  protected const DEFAULT_REBUILD_DELAY = 300;

  /**
   * Largest change set applied incrementally when config says nothing.
   */
  protected const DEFAULT_MAX_INCREMENTAL_ITEMS = 100;

  /**
   * The build lock lease, in seconds.
   *
   * Only has to outlive one chunk: LockRenewingProgressReporter renews it at
   * every chunk boundary, so a long build keeps the lock and a crashed one
   * releases it in minutes rather than an hour.
   */
  protected const LOCK_TIMEOUT = 300;

  /**
   * Upper bound on queue items aggregated into one change set.
   *
   * Guards against an unbounded claim loop on a queue that a bulk operation
   * filled with hundreds of thousands of items. Anything past the cap stays
   * queued for the next run.
   */
  protected const MAX_CLAIMED_ITEMS = 50000;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly LockBackendInterface $lock,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly StreamWrapperManagerInterface $streamWrapperManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly StateInterface $state,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected readonly LoggerInterface $logger,
    protected readonly ScoltaContentGatherer $contentGatherer,
    protected readonly QueueFactory $queueFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('lock'),
      $container->get('config.factory'),
      $container->get('file_system'),
      $container->get('stream_wrapper_manager'),
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('cache_tags.invalidator'),
      $container->get('logger.channel.scolta'),
      $container->get('scolta.content_gatherer'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // Debounce: wait until the configured delay has elapsed since the LAST
    // content change so a burst of edits coalesces into one rebuild.
    $requestedAt = (int) $this->state->get('scolta.rebuild_requested_at', 0);
    if ($requestedAt > 0) {
      $delay = $this->autoRebuildDelay();
      $remaining = ($requestedAt + $delay) - time();
      if ($remaining > 0) {
        throw new SuspendQueueException(
          sprintf('Debouncing Scolta rebuild: %d seconds until the rebuild delay elapses.', $remaining),
          0,
          NULL,
          (float) $remaining
        );
      }
    }

    if (!$this->lock->acquire('scolta_build', self::LOCK_TIMEOUT)) {
      throw new SuspendQueueException('Build lock held.');
    }

    try {
      $config = $this->configFactory->get('scolta.settings');

      $outputDir = $this->resolveDir($config->get('pagefind.output_dir') ?? 'public://scolta-pagefind');
      // The default matches config/install/scolta.settings.yml. It read
      // private:// here and public:// in the Drush command, so a site with no
      // saved pagefind config had the queue worker and `drush scolta:build`
      // reading two different state directories — each one rebuilding from
      // scratch because the other's manifest and ledger were invisible to it.
      $stateDir = $this->resolveDir($config->get('pagefind.build_dir') ?? 'public://scolta-build');

      // Ensure directories exist.
      if (!is_dir($stateDir) && !$this->fileSystem->mkdir($stateDir, 0755, TRUE)) {
        $this->logger->error('Failed to create state directory: @dir', ['@dir' => $stateDir]);
        return;
      }
      if (!is_dir($outputDir) && !$this->fileSystem->mkdir($outputDir, 0755, TRUE)) {
        $this->logger->error('Failed to create output directory: @dir', ['@dir' => $outputDir]);
        return;
      }

      $siteName = $config->get('site_name') ?: ($this->configFactory->get('system.site')->get('name') ?? '');
      $language = $config->get('ai_languages')[0] ?? 'en';

      // Aggregate every rebuild request visible right now into one change set.
      // Anything enqueued after this claim loop returns is deliberately left
      // in the queue: it belongs to the next run, and deleting it here is how
      // an edit that lands mid-build gets lost.
      $claimed = [];
      $changeSet = $this->collectChangeSet($data, $claimed);

      if ($this->tryIncrementalUpdate($changeSet, $config, $stateDir, $outputDir, $siteName, $language)) {
        $this->deleteClaimed($claimed);
        return;
      }

      $totalCount = $this->contentGatherer->gatherCount('node', '');
      if ($totalCount === 0) {
        $this->logger->info('No content found to index.');
        $this->deleteClaimed($claimed);
        return;
      }

      $budget = MemoryBudgetConfig::fromCliAndConfig(NULL, NULL, fn() => [
        'profile' => $config->get('memory_budget.profile') ?? 'conservative',
        'chunk_size' => $config->get('memory_budget.chunk_size'),
      ]);

      $orchestrator = new IndexBuildOrchestrator($stateDir, $outputDir, NULL, $language);
      $intent = BuildIntentFactory::fromFlags(FALSE, FALSE, $totalCount, $budget);

      // Stream content one entity at a time through the shared gatherer —
      // translations, text-format rendering, field mappings, and the alter
      // hook all apply, and the timestamp manifest lets unchanged entities
      // skip the full load. No eager loadMultiple() of the whole corpus.
      // One manifest instance for both: the gatherer reads it to skip
      // unchanged entities and writes what it loads, and the exporter records
      // the bodies it drops for being too short to index, which is the only
      // place that decision is made against a body in memory.
      $tsManifest = $orchestrator->getTimestampManifest();
      $exporter = new ContentExporter($outputDir);
      $items = $exporter->filterItems(
        $this->contentGatherer->gather('node', '', $siteName, NULL, $tsManifest, FALSE),
        $tsManifest
      );

      // The reporter renews the build lock at every chunk boundary, so the
      // lease only has to outlive one chunk rather than the whole build.
      $reporter = new LockRenewingProgressReporter($this->lock, 'scolta_build', self::LOCK_TIMEOUT);
      $report = $orchestrator->build($intent, $items, $this->logger, $reporter);

      if ($report->success) {
        $this->bumpGeneration();
        $this->deleteClaimed($claimed);
        $this->logger->info('Search index rebuilt via queue: @pages pages in @time s.', [
          '@pages' => $report->pagesProcessed,
          '@time' => $report->durationSeconds,
        ]);
      }
      else {
        $this->logger->error('Queue index rebuild failed: @error', [
          '@error' => $report->error ?? 'unknown',
        ]);
      }
    }
    finally {
      $this->lock->release('scolta_build');
    }
  }

  /**
   * Aggregate the queued rebuild requests this run will cover.
   *
   * Claims every rebuild request currently in the queue and folds it into one
   * change set, keeping the claimed handles so the caller can delete exactly
   * what it covered. Claiming up front is what makes cover-only deletion
   * possible: an item enqueued after this returns is never claimed here, so
   * it survives the build and is picked up by the next run.
   *
   * A payload is "targeted" only when it names the entity and the content
   * item IDs it changed. The install hook and the search_api backend enqueue
   * bare full-rebuild markers, and so did every version of the entity hooks
   * before this one, so a queue holding any of those forces a full rebuild.
   *
   * @param mixed $data
   *   The payload of the item the queue runner handed to processItem(). The
   *   runner owns that item and deletes it itself, so it is folded into the
   *   change set but never into $claimed.
   * @param array $claimed
   *   Filled with the claimed queue item handles, by reference.
   *
   * @return array
   *   The aggregated change set.
   */
  protected function collectChangeSet($data, array &$claimed): array {
    $changeSet = [
      'targeted' => TRUE,
      'upsert_entity_ids' => [],
      'upsert_item_ids' => [],
      'delete_item_ids' => [],
    ];

    $this->foldPayload($data, $changeSet);

    $queue = $this->queueFactory->get('scolta_rebuild');
    // Lease the claims for a full lock period: a claim that lapses mid-build
    // can be handed to a second worker, which then blocks on the build lock
    // and suspends the queue for no reason.
    while (count($claimed) < self::MAX_CLAIMED_ITEMS) {
      $item = $queue->claimItem(self::LOCK_TIMEOUT);
      if (!is_object($item)) {
        break;
      }
      $claimed[] = $item;
      $this->foldPayload($item->data, $changeSet);
    }

    if (count($claimed) >= self::MAX_CLAIMED_ITEMS) {
      $this->logger->warning('Scolta rebuild queue exceeded @cap items in one run; the remainder stays queued for the next run.', [
        '@cap' => self::MAX_CLAIMED_ITEMS,
      ]);
    }

    return $changeSet;
  }

  /**
   * Fold one queue payload into the change set.
   */
  protected function foldPayload($data, array &$changeSet): void {
    if (!is_array($data)) {
      $changeSet['targeted'] = FALSE;
      return;
    }

    $op = $data['op'] ?? '';
    $itemIds = $data['item_ids'] ?? [];
    $entityId = $data['entity_id'] ?? NULL;
    // Only nodes are gathered today, so a payload naming anything else cannot
    // be applied incrementally without silently indexing the wrong storage.
    $entityType = $data['entity_type'] ?? 'node';

    if (!is_array($itemIds) || $itemIds === [] || $entityType !== 'node') {
      $changeSet['targeted'] = FALSE;
      return;
    }

    if ($op === 'delete') {
      foreach ($itemIds as $itemId) {
        $changeSet['delete_item_ids'][(string) $itemId] = TRUE;
        // A re-created ID must not stay staged for deletion.
        unset($changeSet['upsert_item_ids'][(string) $itemId]);
      }
      return;
    }

    if ($op !== 'insert' && $op !== 'update') {
      $changeSet['targeted'] = FALSE;
      return;
    }

    if ($entityId === NULL) {
      $changeSet['targeted'] = FALSE;
      return;
    }

    $changeSet['upsert_entity_ids'][(string) $entityId] = $entityId;
    foreach ($itemIds as $itemId) {
      $changeSet['upsert_item_ids'][(string) $itemId] = TRUE;
      unset($changeSet['delete_item_ids'][(string) $itemId]);
    }
  }

  /**
   * Apply the change set through the incremental index update path.
   *
   * Gathers exactly the changed entities rather than the whole corpus, which
   * is the difference between an edit costing seconds and an edit costing a
   * full build. Returns FALSE whenever the update cannot be done exactly, and
   * the caller falls back to a full rebuild.
   *
   * @return bool
   *   TRUE when the index was updated incrementally.
   */
  protected function tryIncrementalUpdate(array $changeSet, $config, string $stateDir, string $outputDir, string $siteName, string $language): bool {
    if (!($config->get('incremental.enabled') ?? TRUE)) {
      return FALSE;
    }

    // A site whose resolved scolta-php predates the incremental API takes the
    // full build path instead of fatally erroring on a missing class.
    if (!class_exists(IncrementalIndexUpdater::class)) {
      $this->logger->info('Incremental index updates need a newer tag1/scolta-php; falling back to a full rebuild.');
      return FALSE;
    }

    if (!$changeSet['targeted']) {
      $this->logger->info('A queued rebuild request did not name what changed; falling back to a full rebuild.');
      return FALSE;
    }

    $touched = count($changeSet['upsert_item_ids']) + count($changeSet['delete_item_ids']);
    if ($touched === 0) {
      return FALSE;
    }

    $threshold = (int) ($config->get('incremental.max_changed_items') ?? self::DEFAULT_MAX_INCREMENTAL_ITEMS);
    if ($threshold > 0 && $touched > $threshold) {
      $this->logger->warning('Change set of @count items exceeds the incremental threshold of @max; falling back to a full rebuild.', [
        '@count' => $touched,
        '@max' => $threshold,
      ]);
      return FALSE;
    }

    $updater = new IncrementalIndexUpdater($stateDir, $outputDir, $language, NULL, $this->logger);
    if (!$updater->isAvailable()) {
      $this->logger->warning('No page-table ledger for the existing index; falling back to a full rebuild. Incremental updates apply to an index, they do not create one.');
      return FALSE;
    }

    // Gather only entities that are still published: an unpublish arrives as
    // an update, and staging its content as an upsert would keep a hidden
    // node in the index.
    $publishedIds = $this->contentGatherer->publishedIds('node', array_values($changeSet['upsert_entity_ids']));

    $produced = [];
    foreach ($this->contentGatherer->gatherByIds('node', $publishedIds, $siteName) as $item) {
      $updater->stageUpsert($item);
      $produced[(string) $item->id] = TRUE;
    }

    // Every page we expected but did not produce has left the index: the node
    // was deleted or unpublished, its body was emptied, or a translation was
    // removed. Each of those leaves a stale page behind without this.
    foreach (array_diff_key($changeSet['upsert_item_ids'], $produced) as $itemId => $unused) {
      $updater->stageDelete((string) $itemId);
    }
    foreach (array_keys($changeSet['delete_item_ids']) as $itemId) {
      $updater->stageDelete((string) $itemId);
    }

    try {
      $result = $updater->commit();
    }
    catch (IncrementalUpdateUnavailable $e) {
      $this->logger->warning('Incremental index update unavailable (@reason); falling back to a full rebuild.', [
        '@reason' => $e->getMessage(),
      ]);
      return FALSE;
    }

    $this->bumpGeneration();

    $this->logger->info('Search index updated incrementally: @updated pages updated, @deleted deleted, @fragments fragments, @chunks chunks rewritten in @time s (tombstones @tombstones%).', [
      '@updated' => $result->pagesUpdated,
      '@deleted' => $result->pagesDeleted,
      '@fragments' => $result->fragmentsWritten,
      '@chunks' => $result->chunksRewritten,
      '@time' => round($result->durationSeconds, 3),
      '@tombstones' => round($result->tombstoneRatio * 100, 1),
    ]);

    return TRUE;
  }

  /**
   * Delete exactly the queue items this run covered.
   *
   * Never drains the queue: an item that arrived after collectChangeSet()
   * claimed its batch describes a change this build did not see, and deleting
   * it would drop that edit permanently.
   */
  protected function deleteClaimed(array $claimed): void {
    $queue = $this->queueFactory->get('scolta_rebuild');
    foreach ($claimed as $item) {
      $queue->deleteItem($item);
    }
  }

  /**
   * Bump the generation counter so cached AI responses refresh.
   */
  protected function bumpGeneration(): void {
    $generation = $this->state->get('scolta.generation', 0);
    $this->state->set('scolta.generation', $generation + 1);
    $this->cacheTagsInvalidator->invalidateTags(['scolta_search_index']);
  }

  /**
   * The debounce delay: the Scolta backend's auto_rebuild_delay setting.
   *
   * Read from the first enabled search_api server using the scolta_pagefind
   * backend; falls back to 300 seconds when none exists (e.g. rebuilds
   * triggered purely by scolta.module's entity hooks).
   */
  protected function autoRebuildDelay(): int {
    try {
      $servers = $this->entityTypeManager->getStorage('search_api_server')->loadMultiple();
      foreach ($servers as $server) {
        // method_exists() rather than instanceof ServerInterface: search_api
        // classes are not autoloadable in every analysis environment.
        if (method_exists($server, 'getBackendId') && method_exists($server, 'getBackendConfig')
          && $server->getBackendId() === 'scolta_pagefind') {
          $backendConfig = $server->getBackendConfig();
          return max(60, min(3600, (int) ($backendConfig['auto_rebuild_delay'] ?? self::DEFAULT_REBUILD_DELAY)));
        }
      }
    }
    catch (\Throwable $e) {
      // search_api server storage unavailable — use the default.
    }
    return self::DEFAULT_REBUILD_DELAY;
  }

  /**
   * Resolve a stream-wrapper URI to a filesystem path.
   */
  protected function resolveDir(string $dir): string {
    if (str_contains($dir, '://')) {
      try {
        return $this->streamWrapperManager->getViaUri($dir)->realpath() ?: $dir;
      }
      catch (\Exception $e) {
        // Fall through with stream URI.
      }
    }
    return $dir;
  }

}
