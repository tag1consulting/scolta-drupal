<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\QueueWorker;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\DelayedRequeueException;
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
 * the scolta.rebuild_requested_at state key, and the worker delays the
 * item until the backend's auto_rebuild_delay has elapsed since that
 * change, so a burst of edits produces one build. The delay is capped at
 * MAX_DEBOUNCE_MULTIPLIER windows since the oldest unserved request, so a
 * write stream faster than the window cannot starve the index.
 *
 * "Not now" is always signalled with DelayedRequeueException, never
 * SuspendQueueException: `drush queue:run` turns a suspend into a non-zero
 * exit, which pages whoever reads the cron mail for a tick that merely found
 * a build running or a debounce window still open.
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
   * The queue this worker serves.
   *
   * @since 1.4.1
   * @stability experimental
   */
  public const QUEUE_NAME = 'scolta_rebuild';

  /**
   * Debounce delay when scolta.settings says nothing.
   */
  protected const DEFAULT_REBUILD_DELAY = 300;

  /**
   * State key holding the oldest unserved rebuild request.
   *
   * Set only when unset, and cleared when a build is allowed to start, so it
   * marks how long the currently pending batch of changes has been waiting.
   */
  public const FIRST_REQUEST_KEY = 'scolta.rebuild_first_requested_at';

  /**
   * Multiples of the debounce delay after which the debounce stops applying.
   *
   * Without a ceiling, any workload saving content more often than once per
   * delay window resets the timer forever and the index is never rebuilt —
   * an attachment-text backfill saving thousands of nodes an hour, or a busy
   * site at peak editing.
   */
  protected const MAX_DEBOUNCE_MULTIPLIER = 4;

  /**
   * Largest change set applied incrementally when config says nothing.
   *
   * Not the crossover — well under it. Measured against scolta-php's
   * SyntheticCorpus (the fixture behind its IncrementalUpdateBenchmarkTest),
   * a 20,000-page index on an M-series laptop: a full build took 52 s, and an
   * incremental commit took 22 s of fixed cost plus ~3 ms per changed page,
   * so the two paths cost the same at about 8,000 changed pages — 40% of the
   * corpus. A 5,000-page index crossed over at ~46%. That fixture holds its
   * corpus in memory, so its "full build" pays no CMS gather at all, which
   * makes 40% a floor: on Share My Lesson's 109,308 pages the gather was
   * 84.7% of a 2,364 s build, and an update never gathers a page it was not
   * told about, which puts the real crossover near 90% of the corpus.
   *
   * What binds instead is the commit's working set, which grows with the
   * change set rather than with the corpus: ~82 KB per changed page over a
   * 64 MB floor, measured one change-set size per process — 142 MB at 1,000
   * pages, 224 MB at 2,000, 480 MB at 5,000. 1,000 keeps a cron run inside a
   * 256 MB memory_limit with Drupal's own bootstrap alongside it, and covers
   * the bulk operations that motivated raising this from 100 (a bulk publish,
   * a taxonomy change, an attachment-text backfill — several hundred to a
   * couple of thousand nodes per run, every one of which used to fall back to
   * a full rebuild of a six-figure index).
   *
   * A site with more headroom raises incremental.max_changed_items; the
   * ceiling worth knowing about is LOCK_TIMEOUT, since this path holds the
   * build lock without renewing it.
   */
  public const DEFAULT_MAX_INCREMENTAL_ITEMS = 1000;

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
   * Consecutive failed segments tolerated before a build is given up on.
   *
   * A segment that dies on a transient infrastructure fault (a dropped redis
   * connection, a database restart) is indistinguishable from one that died
   * on a corrupt corpus, so the marker is kept and the next tick retries;
   * this bounds that so a genuinely broken build stops instead of retrying
   * every minute forever.
   */
  protected const MAX_SEGMENT_FAILURES = 3;

  /**
   * State key holding the consecutive segment failure count.
   */
  protected const FAILURE_COUNT_KEY = 'scolta.rebuild_segment_failures';

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
   * State key: the full build the marker stands for was requested forced.
   *
   * A forced request (`scolta_queue_full_rebuild($reason, TRUE)`) is folded
   * into the build and deleted from the queue when the build starts, so the
   * flag has to outlive the payload: a resumed or chained segment that lost
   * it would serve every entity the manifest still covers from cache, and
   * the tail of the build would silently be unforced.
   */
  protected const FORCE_KEY = 'scolta.rebuild.force';

  /**
   * Whether a build segment already ran in this process.
   *
   * A segment that yielded on memory pressure leaves the heap it ran in
   * fragmented; a second segment in the same process hits the wall at once
   * and is judged a stall. The queue runner reuses one worker instance for a
   * run, so every further item in the run is delayed to the next tick.
   */
  protected bool $segmentRan = FALSE;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly LockBackendInterface $lock,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly StateInterface $state,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected readonly LoggerInterface $logger,
    protected readonly ScoltaContentGatherer $contentGatherer,
    protected readonly QueueFactory $queueFactory,
    protected readonly IndexBuildRunner $runner,
    protected readonly TimeInterface $time,
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
      $container->get('state'),
      $container->get('cache_tags.invalidator'),
      $container->get('logger.channel.scolta'),
      $container->get('scolta.content_gatherer'),
      $container->get('queue'),
      $container->get('scolta.index_build_runner'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if ($this->segmentRan) {
      throw new DelayedRequeueException(60, 'A Scolta build segment already ran in this process; the next cron run continues the build.');
    }

    // Debounce: wait until the configured delay has elapsed since the LAST
    // content change so a burst of edits coalesces into one rebuild. The
    // marker is exempt: it is an interrupted build's own continuation, not a
    // new request, and every save rewrites the requested-at key, so on a site
    // edited more often than the delay a debounced marker would leave a
    // half-built index waiting for a quiet period that never comes.
    $requestedAt = (int) $this->state->get('scolta.rebuild_requested_at', 0);
    if ($requestedAt > 0 && $data !== self::RESUME_MARKER) {
      $delay = $this->autoRebuildDelay();
      $now = $this->time->getCurrentTime();
      $remaining = ($requestedAt + $delay) - $now;
      // ...but never longer than MAX_DEBOUNCE_MULTIPLIER windows since the
      // oldest unserved request, or a sustained write stream would reset the
      // timer indefinitely and starve the index.
      $firstRequestedAt = (int) $this->state->get(self::FIRST_REQUEST_KEY, 0);
      $starved = $firstRequestedAt > 0
        && $now >= $firstRequestedAt + ($delay * self::MAX_DEBOUNCE_MULTIPLIER);
      if ($remaining > 0 && !$starved) {
        throw new DelayedRequeueException($remaining, sprintf('Debouncing Scolta rebuild: %d seconds until the rebuild delay elapses.', $remaining));
      }
      if ($starved) {
        $this->logger->info('Scolta rebuild debounce capped: content has changed continuously for @seconds seconds, building anyway.', [
          '@seconds' => $now - $firstRequestedAt,
        ]);
      }
    }
    if (!$this->lock->acquire(IndexBuildRunner::LOCK_NAME, self::LOCK_TIMEOUT)) {
      throw new DelayedRequeueException(60, 'Build lock held.');
    }
    // Past the debounce and holding the lock: whatever is pending is about to
    // be served, so the next request starts a fresh max-wait window. Not
    // before the lock — a tick that turns back at a held lock has served
    // nothing, and clearing the window there would let the next save reopen
    // it and reset the cap this is here to guarantee.
    $this->state->delete(self::FIRST_REQUEST_KEY);

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
      // A new request starts the failure count over; the marker does not —
      // it is this build's own standing request, and a build retrying a
      // failed first segment arrives here looking exactly like a new one.
      if ($data !== self::RESUME_MARKER) {
        $this->state->delete(self::FAILURE_COUNT_KEY);
      }
      $this->deleteClaimed($claimed);

      // Acting on the flag here, after the lock, is what makes a forced
      // request race-free: a build already running does not see it, and the
      // next one, which starts after that build wrote its manifest, does.
      $force = !empty($changeSet['force']);
      if ($force) {
        $this->state->set(self::FORCE_KEY, TRUE);
      }
      else {
        $this->state->delete(self::FORCE_KEY);
      }

      $intent = BuildIntentFactory::fromFlags(FALSE, FALSE, $totalCount, $this->runner->memoryBudget());
      $report = $this->runSegment($orchestrator, $intent, $entityTypes, [], $force);
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
    $report = $this->runSegment($orchestrator, $intent, $this->runner->entityTypes(), $this->runner->resumeCursors($orchestrator), $this->forced());
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
      $this->state->delete(self::FAILURE_COUNT_KEY);
      $this->state->delete(self::FORCE_KEY);
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

    // A segment that died on something other than memory pressure may have
    // died on a transient fault — a dropped redis connection, a database
    // restart — which reads exactly like an unrecoverable one. Keep the
    // marker so the next tick retries, bounded by MAX_SEGMENT_FAILURES so a
    // build that is genuinely broken still stops. A memory abort reaching
    // here was judged a stall by the policy, and retrying it walks into the
    // same wall, so it is given up on at once as before.
    if (!$report->isMemoryAbort()) {
      $failures = (int) $this->state->get(self::FAILURE_COUNT_KEY, 0) + 1;
      if ($failures < self::MAX_SEGMENT_FAILURES) {
        $this->state->set(self::FAILURE_COUNT_KEY, $failures);
        $this->ensureMarker();
        $this->logger->warning('Queue index rebuild segment failed (attempt @attempt of @max): @error. The next tick retries.', [
          '@attempt' => $failures,
          '@max' => self::MAX_SEGMENT_FAILURES,
          '@error' => $report->error ?? 'unknown',
        ]);
        if (!$dataCovered) {
          throw new RequeueException('This rebuild request arrived while a build was in progress; it is applied to the finished index on a later run.');
        }
        return;
      }
    }

    // A chained failure already carries the policy's reason; a segment that
    // failed in this process is judged (and a stall recorded) here, so the
    // next run starts fresh instead of resuming into the same wall. Given up
    // on: the marker goes, and the runner deletes $data as for any failure.
    $reason = $report->isMemoryAbort() ? $this->policy()->stopReason($report, $buildState) : $report->error;
    $this->state->delete(self::FAILURE_COUNT_KEY);
    $this->state->delete(self::FORCE_KEY);
    $this->deleteMarkers();
    $this->logger->error('Queue index rebuild failed after @attempts attempts: @error', [
      '@attempts' => $report->isMemoryAbort() ? 1 : self::MAX_SEGMENT_FAILURES,
      '@error' => $reason ?? 'unknown',
    ]);
  }

  /**
   * Run the remaining segments as child processes, in this tick.
   *
   * @return \Tag1\Scolta\Index\StatusReport|null
   *   The chain's final report, or NULL when no child can be spawned here.
   */
  protected function chain(BuildState $buildState, StatusReport $yielded): ?StatusReport {
    try {
      return $this->runner->resumeChain($buildState, $yielded, $this->runner->memoryBudget(), $this->logger, function (array $options, array $env): int {
        // Renew the Drupal lock while the child holds the state lock, so
        // a tick during a child segment still exits at the lock instead of
        // reaching the state directory and reading contention as a failure.
        return $this->runner->runDrush('scolta:build', $options, $env, fn() => $this->lock->acquire(IndexBuildRunner::LOCK_NAME, self::LOCK_TIMEOUT));
      }, $this->forced() ? ['force' => TRUE] : []);
    }
    catch (\RuntimeException $e) {
      // Drush could not launch a child here; the marker carries the build to
      // the next tick instead. Anything else is a bug and propagates.
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
   * @param bool $force
   *   Reload every entity instead of serving manifest-cached fragments.
   */
  protected function runSegment(IndexBuildOrchestrator $orchestrator, BuildIntent $intent, array $entityTypes, array $cursors, bool $force = FALSE): StatusReport {
    $this->segmentRan = TRUE;
    // The reporter renews the build lock at every chunk boundary, so the
    // lease only has to outlive one chunk rather than the whole build, and
    // logs a progress line there so queue:run shows how far along
    // the build is.
    $reporter = new LockRenewingProgressReporter($this->lock, IndexBuildRunner::LOCK_NAME, self::LOCK_TIMEOUT, $this->logger);
    return $this->runner->runSegment($orchestrator, $intent, $entityTypes, $cursors, $this->logger, $reporter, force: $force);
  }

  /**
   * Whether the build the marker stands for was requested forced.
   */
  protected function forced(): bool {
    return (bool) $this->state->get(self::FORCE_KEY, FALSE);
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
    $this->queueFactory->get(self::QUEUE_NAME)->createItem(self::RESUME_MARKER);
  }

  /**
   * Delete every claimable resume marker; every other item goes back as it was.
   */
  protected function deleteMarkers(): void {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
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
   * item IDs it changed. The install hook enqueues a bare full-rebuild
   * marker, and so did every version of the entity hooks before this one, so
   * a queue holding any of those forces a full rebuild.
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
      'force' => FALSE,
      'upsert_entity_ids' => [],
      'upsert_item_ids' => [],
      'delete_item_ids' => [],
    ];

    $this->foldPayload($data, $changeSet);

    $queue = $this->queueFactory->get(self::QUEUE_NAME);
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
    // the safe answer, and a mostly-skipped one when the manifest is current
    // unless a request folded into it is forced.
    if (!is_array($data)) {
      $changeSet['targeted'] = FALSE;
      return;
    }

    // A forced request reindexes content that did not change. Targeted
    // (drush scolta:reindex), the timestamp manifest is rewritten for its
    // pages, see tryIncrementalUpdate(); untargeted
    // (scolta_queue_full_rebuild($reason, TRUE)), the full build reloads
    // every entity as `drush scolta:build --force` does.
    if (!empty($data['force'])) {
      $changeSet['force'] = TRUE;
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
    //
    // A forced reindex gets the timestamp manifest as well. Without it the
    // gather never calls TimestampManifest::put(), so the manifest keeps the
    // entity's old timestamp and old item data — harmless after a real save,
    // which moves `changed` and makes the entry stale, but fatal here: nothing
    // moved `changed`, so the next full build would find the entry fresh and
    // serve the cached page this run just replaced.
    $force = !empty($changeSet['force']);
    $manifest = $force
      ? $this->createOrchestrator($stateDir, $outputDir, $language)->getTimestampManifest()
      : NULL;

    $produced = [];
    foreach ($changeSet['upsert_entity_ids'] as $entityType => $entityIds) {
      $publishedIds = $this->contentGatherer->publishedIds($entityType, array_values($entityIds));
      foreach ($this->contentGatherer->gatherByIds($entityType, $publishedIds, $siteName, $manifest, $force) as $item) {
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

    // saveWithoutPruning(), never pruneAndSave(): this run saw a handful of
    // entities, and pruning would drop the manifest entry of every other one.
    $manifest?->saveWithoutPruning();

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
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
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
   * The debounce delay: scolta.settings pagefind.auto_rebuild_delay, 60-3600.
   */
  protected function autoRebuildDelay(): int {
    $delay = $this->configFactory->get('scolta.settings')->get('pagefind.auto_rebuild_delay');
    return max(60, min(3600, (int) ($delay ?? self::DEFAULT_REBUILD_DELAY)));
  }

}
