<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\QueueWorker;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\Progress\LockRenewingProgressReporter;
use Drupal\scolta\Service\IndexBuildRunner;
use Drupal\scolta\Service\ScoltaContentGatherer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IncrementalIndexUpdater;
use Tag1\Scolta\Index\IncrementalUpdateUnavailable;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\ResumeChainPolicy;
use Tag1\Scolta\Index\StatusReport;

/**
 * Queue worker for rebuilding the Scolta search index.
 *
 * Not run by Drupal cron. Rebuilds run from an external cron line, once a
 * minute: `* * * * * drush queue:run scolta_rebuild`. A tick that finds a
 * build running exits at the build lock in about a second; a tick that finds
 * an interrupted build on disk continues it. The queue item is the request;
 * the state directory is the truth about what the build has done.
 *
 * Runs the same streamed pipeline as `drush scolta:build`, through the shared
 * IndexBuildRunner: ScoltaContentGatherer (translations, text-format
 * rendering, field mappings, alter hook; 10 entities per load) →
 * ContentExporter::filterItems() → IndexBuildOrchestrator.
 *
 * Rebuilds are debounced: scolta.module records the last content change in
 * the scolta.rebuild_requested_at state key, and the worker suspends the
 * queue until the backend's auto_rebuild_delay has elapsed since that
 * change, so a burst of edits produces one build.
 *
 * A full build enqueues one RESUME_MARKER item before its first segment runs
 * and deletes it only when the build completes or is given up on, so a
 * process killed mid-segment (an evicted pod, the OOM killer) leaves a
 * claimable request behind whatever the queue runner's lease was. A build too
 * large for one process yields on memory pressure and is chained to
 * completion in this process by spawning `drush scolta:build --resume`
 * segments (scolta-php's ResumeChainRunner); if that is impossible the
 * marker carries the build to the next tick, one segment per tick. Each run
 * decides from the state directory alone (ResumeChainPolicy::resumable())
 * whether it is starting a build or continuing one.
 *
 * @QueueWorker(
 *   id = "scolta_rebuild",
 *   title = @Translation("Scolta Index Rebuild")
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
   * every chunk boundary, and the chain renews it while waiting on a child
   * segment, so a long build keeps the lock and a killed one releases it in
   * minutes rather than an hour.
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

  /**
   * The queue payload that stands in for a full build in progress.
   *
   * Enqueued before a segment runs, in place of the requests folded into the
   * build: the walk covers every entity, so those requests are served the
   * moment it completes, and a process killed mid-segment leaves this behind
   * as the request the next tick acts on. A request arriving after the walk
   * started is not covered — the walk may already have passed its entity —
   * and the marker is what keeps the two apart: a resumed segment requeues
   * any other payload on success. Exactly one is kept; extras are drained.
   * With no interrupted build on disk it asks for a full build.
   */
  protected const RESUME_MARKER = ['op' => 'resume'];

  /**
   * Whether a build segment already ran in this process.
   *
   * A segment that yielded on memory pressure leaves the heap it ran in
   * fragmented; a second segment in the same process hits the wall at once
   * and is judged a stall. The queue runner reuses one worker instance for a
   * run, so this suspends the queue for the rest of the run.
   */
  protected bool $segmentRan = FALSE;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly LockBackendInterface $lock,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly StateInterface $state,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected readonly LoggerInterface $logger,
    protected readonly ScoltaContentGatherer $contentGatherer,
    protected readonly QueueFactory $queueFactory,
    protected readonly IndexBuildRunner $runner,
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
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('cache_tags.invalidator'),
      $container->get('logger.channel.scolta'),
      $container->get('scolta.content_gatherer'),
      $container->get('queue'),
      $container->get('scolta.index_build_runner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if ($this->segmentRan) {
      throw new SuspendQueueException('A Scolta build segment already ran in this process; the next cron run continues the build.');
    }

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

    if (!$this->lock->acquire(IndexBuildRunner::LOCK_NAME, self::LOCK_TIMEOUT)) {
      throw new SuspendQueueException('Build lock held.');
    }

    try {
      $config = $this->configFactory->get('scolta.settings');
      try {
        $outputDir = $this->runner->outputDir();
        $stateDir = $this->runner->stateDir($this->logger);
      }
      catch (\RuntimeException $e) {
        $this->logger->error($e->getMessage());
        return;
      }
      $language = $this->runner->language();

      $orchestrator = $this->createOrchestrator($stateDir, $outputDir, $language);
      $buildState = $orchestrator->coordinator()->buildState();

      // An interrupted build on disk takes precedence over whatever this
      // request asks for: the incremental updater and a fresh build both
      // write the ledger that build's remaining segments depend on.
      if (ResumeChainPolicy::resumable($buildState)) {
        $this->resumeBuild($data, $orchestrator, $buildState, $outputDir);
        return;
      }

      // Aggregate every rebuild request visible right now into one change set.
      // Anything enqueued after this claim loop returns is deliberately left
      // in the queue: it belongs to the next run, and deleting it here is how
      // an edit that lands mid-build gets lost.
      $claimed = [];
      $changeSet = $this->collectChangeSet($data, $claimed);

      if ($this->tryIncrementalUpdate($changeSet, $config, $stateDir, $outputDir, $this->runner->siteName(), $language)) {
        $this->deleteClaimed($claimed);
        return;
      }

      $entityTypes = $this->runner->entityTypes();
      $totalCount = array_sum(array_map(fn(string $type) => $this->contentGatherer->gatherCount($type, ''), $entityTypes));
      if ($totalCount === 0) {
        $this->logger->info('No content found to index.');
        $this->deleteClaimed($claimed);
        return;
      }

      // From here the marker is the request. The walk this build starts
      // covers every entity, so the requests folded into it are served when
      // it completes, and a kill before then leaves the marker for the next
      // tick to act on.
      $this->ensureMarker();
      $this->deleteClaimed($claimed);

      $intent = BuildIntentFactory::fromFlags(FALSE, FALSE, $totalCount, $this->runner->memoryBudget());
      $report = $this->runSegment($orchestrator, $intent, $entityTypes, [], $outputDir);
      $this->finish(TRUE, $report, $buildState, 'Search index rebuilt via queue: @pages pages in @time s.');
    }
    finally {
      $this->lock->release(IndexBuildRunner::LOCK_NAME);
    }
  }

  /**
   * Run one more segment of the interrupted build on disk.
   *
   * Claims no other queue items: the requests the build covers were replaced
   * by the marker when it started, and anything else in the queue arrived
   * after the walk started and is applied to the finished index on a later
   * run. A marker is made sure of here too, so a build `drush scolta:build`
   * started and lost has its own standing request from the first tick that
   * finds it.
   *
   * @param mixed $data
   *   The payload the queue runner handed to processItem().
   * @param \Tag1\Scolta\Index\IndexBuildOrchestrator $orchestrator
   *   The orchestrator for the state and output directories.
   * @param \Tag1\Scolta\Index\BuildState $buildState
   *   The state directory's build state.
   * @param string $outputDir
   *   The resolved index output directory.
   *
   * @throws \Drupal\Core\Queue\RequeueException
   *   When the payload is not the resume marker and the build did not fail:
   *   it arrived mid-build, so it is applied to the finished index next run.
   */
  protected function resumeBuild($data, IndexBuildOrchestrator $orchestrator, BuildState $buildState, string $outputDir): void {
    $this->ensureMarker();
    $intent = BuildIntent::resume($this->runner->memoryBudget());
    $report = $this->runSegment($orchestrator, $intent, $this->runner->entityTypes(), $this->runner->resumeCursors($orchestrator), $outputDir);
    $this->finish($data === self::RESUME_MARKER, $report, $buildState, 'Search index rebuilt via queue after resuming at segment ' . $buildState->segment() . ': @pages pages in @time s.');
  }

  /**
   * Chain a yielded segment to completion, then settle the marker and $data.
   *
   * @param bool $dataCovered
   *   Whether the build serves the payload the queue runner holds — a request
   *   folded into a fresh build, or the marker itself. The runner deletes it
   *   on a normal return; anything else goes back for the next run.
   * @param \Tag1\Scolta\Index\StatusReport $report
   *   The report of the segment that ran in this process.
   * @param \Tag1\Scolta\Index\BuildState $buildState
   *   The state directory's build state.
   * @param string $successMessage
   *   Logged with @pages and @time when the build completes.
   *
   * @throws \Drupal\Core\Queue\RequeueException
   *   When the payload is a request the build did not cover and the build is
   *   still in progress or completed: it goes back for the next run.
   */
  protected function finish(bool $dataCovered, StatusReport $report, BuildState $buildState, string $successMessage): void {
    if (!$report->success && $report->isMemoryAbort() && $report->chunksWritten > 0) {
      $chained = $this->chain($buildState, $report);
      if ($chained === NULL) {
        // Left resumable on disk with the marker standing; the next tick runs
        // the next segment. A request that arrived mid-build goes back too.
        $this->logger->info('Queue index rebuild yielded on memory pressure after @pages pages; the next tick resumes it.', [
          '@pages' => $report->pagesProcessed,
        ]);
        if (!$dataCovered) {
          throw new RequeueException('This rebuild request arrived while a build was in progress; it is applied to the finished index on a later run.');
        }
        return;
      }
      $report = $chained;
    }

    if ($report->success) {
      $this->bumpGeneration();
      $this->deleteMarkers();
      $this->logger->info($successMessage, [
        '@pages' => $report->pagesProcessed,
        '@time' => $report->durationSeconds,
      ]);
      if (!$dataCovered) {
        throw new RequeueException('This rebuild request arrived while a build was in progress; it is applied to the finished index on the next run.');
      }
      return;
    }

    // A chained failure already carries the policy's reason; a segment that
    // failed in this process is judged (and a stall recorded) here, so the
    // next run starts fresh instead of resuming into the same wall. Given up
    // on: the marker goes, and the runner deletes $data as for any failure.
    $reason = $report->isMemoryAbort() ? $this->policy()->stopReason($report, $buildState) : $report->error;
    $this->deleteMarkers();
    $this->logger->error('Queue index rebuild failed: @error', ['@error' => $reason ?? 'unknown']);
  }

  /**
   * Run the remaining segments as child processes, in this tick.
   *
   * @return \Tag1\Scolta\Index\StatusReport|null
   *   The chain's final report, or NULL when no child can be spawned here.
   */
  protected function chain(BuildState $buildState, StatusReport $yielded): ?StatusReport {
    try {
      return $this->runner->resumeChain($buildState, $yielded, $this->runner->memoryBudget(), $this->logger, function (string $cmd, array $env): int {
        // Renew the Drupal lock while the child holds the state lock, so
        // a tick during a child segment still exits at the lock instead of
        // reaching the state directory and reading contention as a failure.
        return $this->runner->runForeground($cmd, $env, $this->logger, fn() => $this->lock->acquire(IndexBuildRunner::LOCK_NAME, self::LOCK_TIMEOUT));
      });
    }
    catch (\RuntimeException $e) {
      $this->logger->warning($e->getMessage());
      return NULL;
    }
  }

  /**
   * Stream the corpus through one build segment.
   *
   * @param \Tag1\Scolta\Index\IndexBuildOrchestrator $orchestrator
   *   The orchestrator to build with.
   * @param \Tag1\Scolta\Index\BuildIntent $intent
   *   Fresh or resume.
   * @param string[] $entityTypes
   *   The entity types to walk, in order.
   * @param array<string, int> $cursors
   *   Entity type ID => the entity ID to resume that type's walk at.
   * @param string $outputDir
   *   The resolved index output directory.
   */
  protected function runSegment(IndexBuildOrchestrator $orchestrator, BuildIntent $intent, array $entityTypes, array $cursors, string $outputDir): StatusReport {
    $this->segmentRan = TRUE;
    // The reporter renews the build lock at every chunk boundary, so the
    // lease only has to outlive one chunk rather than the whole build.
    $reporter = new LockRenewingProgressReporter($this->lock, IndexBuildRunner::LOCK_NAME, self::LOCK_TIMEOUT);
    return $this->runner->runSegment($orchestrator, $outputDir, $intent, $entityTypes, $cursors, $this->logger, $reporter);
  }

  /**
   * The orchestrator for a build; a seam for tests to inject a pressure probe.
   */
  protected function createOrchestrator(string $stateDir, string $outputDir, string $language): IndexBuildOrchestrator {
    return new IndexBuildOrchestrator($stateDir, $outputDir, NULL, $language);
  }

  /**
   * The policy deciding whether a failed segment is resumed or ends the build.
   */
  protected function policy(): ResumeChainPolicy {
    return $this->runner->policy();
  }

  /**
   * Leave exactly one resume marker in the queue.
   */
  protected function ensureMarker(): void {
    $this->deleteMarkers();
    $this->queueFactory->get('scolta_rebuild')->createItem(self::RESUME_MARKER);
  }

  /**
   * Delete every claimable resume marker; every other item goes back as it was.
   */
  protected function deleteMarkers(): void {
    $queue = $this->queueFactory->get('scolta_rebuild');
    $others = [];
    for ($n = 0; $n < self::MAX_CLAIMED_ITEMS; $n++) {
      $item = $queue->claimItem(self::LOCK_TIMEOUT);
      if (!is_object($item)) {
        break;
      }
      if ($item->data === self::RESUME_MARKER) {
        $queue->deleteItem($item);
      }
      else {
        $others[] = $item;
      }
    }
    foreach ($others as $item) {
      $queue->releaseItem($item);
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
    // The marker asks for a full build: the one it stood for was killed
    // before it left anything to resume, or it outlived its build's success
    // by a crash between publishing and cleanup. Either way a full build is
    // the safe answer, and a mostly-skipped one when the manifest is current.
    if (!is_array($data)) {
      $changeSet['targeted'] = FALSE;
      return;
    }

    $op = $data['op'] ?? '';
    $itemIds = $data['item_ids'] ?? [];
    $entityId = $data['entity_id'] ?? NULL;
    // A payload naming a type the index does not cover cannot be applied
    // incrementally without silently indexing the wrong storage.
    $entityType = $data['entity_type'] ?? 'node';

    if (!is_array($itemIds) || $itemIds === [] || !array_key_exists($entityType, $this->contentGatherer->entityTypes())) {
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

    $changeSet['upsert_entity_ids'][$entityType][(string) $entityId] = $entityId;
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

    // Gather only entities that are still published and in a configured
    // bundle: an unpublish arrives as an update, and staging its content as an
    // upsert would keep a hidden node in the index.
    $produced = [];
    foreach ($changeSet['upsert_entity_ids'] as $entityType => $entityIds) {
      $publishedIds = $this->contentGatherer->publishedIds($entityType, array_values($entityIds));
      foreach ($this->contentGatherer->gatherByIds($entityType, $publishedIds, $siteName) as $item) {
        $updater->stageUpsert($item);
        $produced[(string) $item->id] = TRUE;
      }
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

}
