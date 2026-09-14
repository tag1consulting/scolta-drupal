<?php

declare(strict_types=1);

namespace Drupal\scolta\Commands;

use Consolidation\OutputFormatters\StructuredData\UnstructuredListData;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Cache\DrupalCacheDriver;
use Drupal\scolta\Plugin\QueueWorker\ScoltaRebuildWorker;
use Drupal\scolta\Progress\DrushProgressReporter;
use Drupal\scolta\Service\IndexBuildRunner;
use Drupal\scolta\Service\IndexLocator;
use Drupal\scolta\Service\ScoltaAiService;
use Drupal\scolta\Service\ScoltaContentGatherer;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Index\ResumeChainRunner;
use Tag1\Scolta\Index\RetiredIndexTrash;
use Tag1\Scolta\Index\StatusReport;
use Tag1\Scolta\Prompt\DefaultPrompts;
use Tag1\Scolta\SetupCheck;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * Drush commands for Scolta.
 *
 * Commands: scolta:build gathers content and builds the search index in
 * PHP; scolta:finalize merges committed chunks into the final index;
 * scolta:clear-cache clears the expansion/summary caches; scolta:cleanup
 * deletes retired index (.scolta-trash-*) directories; scolta:status reports
 * index, build directory and AI provider state.
 */
class ScoltaCommands extends DrushCommands {

  /**
   * Constructs a ScoltaCommands object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The default cache backend.
   * @param \Drupal\scolta\Service\ScoltaAiService $aiService
   *   The Scolta AI service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager.
   * @param \Drupal\scolta\Service\ScoltaContentGatherer $contentGatherer
   *   The content gatherer service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param \Drupal\scolta\Service\IndexLocator $indexLocator
   *   The index locator.
   * @param \Drupal\scolta\Service\IndexBuildRunner $runner
   *   The build path shared with the rebuild queue worker.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, to resolve an entity argument to its URL.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly CacheBackendInterface $cache,
    private readonly ScoltaAiService $aiService,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly ScoltaContentGatherer $contentGatherer,
    private readonly FileSystemInterface $fileSystem,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly IndexLocator $indexLocator,
    private readonly IndexBuildRunner $runner,
    private readonly QueueFactory $queueFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Build the search index.
   *
   * Gathers the configured entity types and indexes them in PHP: no HTML is
   * exported and nothing shells out, so it runs on any host.
   *
   * Scoped builds: --bundle and --entity-ids narrow what the build gathers,
   * not what it publishes. A build merges the pages it gathered into a whole
   * new index and cannot carry over a page it never looked at, so a scoped
   * build is a way to build an index holding only that scope — not a way to
   * refresh part of a larger one. If the index already holds pages outside
   * the scope, the build stops before publishing and the index that was
   * already serving stays in place. To reflect an edit to a few nodes, save
   * them and let cron apply the change incrementally.
   */
  #[CLI\Command(name: 'scolta:build', aliases: ['sb'])]
  #[CLI\Option(name: 'entity-type', description: 'Entity type(s) to index, comma-separated')]
  #[CLI\Option(name: 'bundle', description: 'Bundle to index. Scopes the build; see the help text above')]
  #[CLI\Option(name: 'entity-ids', description: 'Comma-separated entity IDs to index. Scopes the build; see the help text above. Unloadable IDs are logged and skipped. --bundle is ignored')]
  #[CLI\Option(name: 'force', description: 'Skip fingerprint check and force a full rebuild')]
  #[CLI\Option(name: 'memory-budget', description: 'Memory profile or byte value for the PHP indexer (e.g. conservative, 256M).')]
  #[CLI\Option(name: 'chunk-size', description: 'Pages per chunk during a PHP index build. Overrides the profile default and config setting.')]
  #[CLI\Option(name: 'resume', description: 'Resume a previously interrupted PHP index build')]
  #[CLI\Option(name: 'restart', description: 'Discard interrupted state and restart the PHP index build. Also discards the page-table ledger, renumbering every page from zero')]
  #[CLI\Option(name: 'reset-ledger', description: 'Discard the page-table ledger under a plain build, renumbering every page from zero. Escape hatch for a corrupt page table (a duplicate page ordinal at the merge) without a full --restart. Cannot be combined with --resume')]
  public function build(
    array $options = [
      'entity-type' => '',
      'bundle' => '',
      'entity-ids' => '',
      'force' => FALSE,
      'memory-budget' => NULL,
      'chunk-size' => NULL,
      'resume' => FALSE,
      'restart' => FALSE,
      'reset-ledger' => FALSE,
    ],
  ): void {
    $config = $this->configFactory->get('scolta.settings');
    $this->buildWithPhpIndexer($options, $config, (bool) $options['force']);

    $this->logger()->notice('Caching resolved prompts...');
    $this->cacheResolvedPrompts();
  }

  /**
   * Build the index in PHP.
   *
   * @param array $options
   *   The command options.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   * @param bool $force
   *   Whether to skip the fingerprint check and force a rebuild.
   */
  private function buildWithPhpIndexer(array $options, $config, bool $force): void {
    $entityTypes = $this->runner->entityTypes($options['entity-type'] ?? '');
    $bundle = $options['bundle'] ?: '';
    $language = $this->runner->language();

    $budget = $this->runner->memoryBudget(
      isset($options['memory-budget']) ? (string) $options['memory-budget'] : NULL,
      isset($options['chunk-size']) ? (string) $options['chunk-size'] : NULL,
    );

    try {
      $resolvedOutputDir = $this->runner->outputDir();
      $resolvedStateDir = $this->runner->stateDir($this->logger());
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return;
    }

    // A bundle or an ID list belongs to one entity type, so scoping a
    // multi-type build is ambiguous rather than merely unusual.
    if (count($entityTypes) > 1 && ($bundle !== '' || ($options['entity-ids'] ?? '') !== '')) {
      throw new \RuntimeException('--bundle and --entity-ids scope one entity type; pass --entity-type=<type> with them.');
    }

    // NULL means "no scoping" — the build walks the whole corpus. An explicit
    // ID list, even one that resolved to nothing, must not fall through to a
    // full walk.
    $entityIds = ($options['entity-ids'] ?? '') !== ''
      ? $this->resolveEntityIds($entityTypes[0], (string) $options['entity-ids'])
      : NULL;

    $totalCount = $entityIds !== NULL
      ? count($entityIds)
      : array_sum(array_map(fn(string $type) => $this->contentGatherer->gatherCount($type, $bundle), $entityTypes));
    if ($totalCount === 0) {
      $this->logger()->warning('No content found to index.');
      return;
    }
    $this->logger()->notice('Gathering content: {count} entities.', ['count' => $totalCount]);

    $resume = (bool) ($options['resume'] ?? FALSE);
    $restart = (bool) ($options['restart'] ?? FALSE);

    // Anything that narrows the gather makes this a partial build, and the
    // orchestrator has to be told: several of its stages read "this build
    // never yielded that page" as "that page was deleted at the source". On
    // production a `--bundle=tntl` build gathered 1,518 pages and released the
    // other ~14,600 ledger rows on exactly that inference, publishing an index
    // of 16,166 fragments with 1,518 live pages. See
    // BuildIntent::withPartialScope().
    //
    // --resume is not passed through: a resumed segment inherits the scope the
    // manifest recorded when the build was started.
    $scoped = $entityIds !== NULL || $bundle !== '';

    try {
      $intent = BuildIntentFactory::fromFlags(
        $resume,
        $restart,
        $totalCount,
        $budget,
        partial: $scoped,
        resetLedger: (bool) ($options['reset-ledger'] ?? FALSE),
      );
    }
    catch (\LogicException $e) {
      // The library's message explains the refusal (--reset-ledger with
      // --resume, or with a partial scope) and what to run instead.
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }

    $reporter = new DrushProgressReporter($this->output());
    $orchestrator = $this->orchestrator($resolvedStateDir, $resolvedOutputDir, $language);

    $resumeCursors = $resume ? $this->runner->resumeCursors($orchestrator) : [];
    foreach ($resumeCursors as $type => $id) {
      $this->logger()->notice('Resuming the {type} walk at entity {id}.', ['type' => $type, 'id' => $id]);
    }

    // The timestamp manifest goes to the gatherer under --force too: --force
    // is a rule about what this build READS (reload every entity, trust
    // nothing cached), and withholding the manifest also stopped the build
    // WRITING to it, so a --force build emptied the very state that makes the
    // next build incremental.
    $report = $this->runner->runSegment($orchestrator, $intent, $entityTypes, $resumeCursors, $this->logger(), $reporter, $bundle, $entityIds, $force);

    if ($report->success) {
      $this->reportBuildSuccess($report, $resolvedOutputDir);
      return;
    }

    // A refusal is not a failure of the indexer; it is the guard working. The
    // published index is untouched, so say what happened and what to do rather
    // than reporting a broken build.
    //
    // Deliberately does not offer --force: that governs what a build READS
    // (reload every entity, trust nothing cached) and not what it gathers, so
    // a scoped --force build gathers the same subset and is refused the same
    // way. Suggesting it would send an operator round the loop again.
    if (str_starts_with((string) $report->error, 'scoped build refused')) {
      throw new \RuntimeException(sprintf(
        "This build was scoped%s, and %s\n\n"
        . "To refresh the whole index, re-run with no scoping options:\n"
        . "  drush scolta:build\n\n"
        . "To reflect an edit to a few entities, no command is needed — saving an\n"
        . "entity queues it and cron applies the change to the published index.\n\n"
        . "To narrow the index to this scope for good, the page-table ledger has to\n"
        . "go first, because it is what still holds the out-of-scope pages. Neither\n"
        . "--restart nor --reset-ledger will do it: both refuse a scoped build, since\n"
        . "an empty ledger is what lets a scoped build delete the rest of the site.\n"
        . "Delete %s and %s and re-run the scoped build; it will renumber every page,\n"
        . 'so every fragment URL changes and visitors refetch the index.',
        $entityIds !== NULL ? ' with --entity-ids' : ' with --bundle=' . $bundle,
        $report->error,
        $resolvedStateDir . '/' . PageTableLedger::FILENAME,
        $resolvedStateDir . '/' . PageTableLedger::JOURNAL_FILENAME,
      ));
    }

    if ($report->error === 'index_only_complete') {
      // PHP heap fragmented after indexing — merge must run in a fresh process.
      $this->logger()->notice('All {pages} pages indexed ({chunks} chunks on disk). Running finalize in a fresh process...', [
        'pages' => $report->pagesProcessed,
        'chunks' => $report->chunksWritten,
      ]);
      $this->spawnFinalize($resolvedStateDir, $resolvedOutputDir, $budget->totalBudgetBytes());
      // spawnFinalize() throws unless the child exited 0, so reaching here
      // means an index was published. Verify it before saying so.
      $this->confirmChainComplete($resolvedOutputDir, FALSE);
      return;
    }

    if ($report->error === 'memory_abort') {
      if ($report->chunksWritten === 0) {
        throw new \RuntimeException(sprintf(
          'Memory limit hit before any chunk was committed, so nothing was indexed. '
          . 'Raise PHP memory_limit (currently %s) or lower --chunk-size, then re-run.',
          ini_get('memory_limit') ?: 'unknown',
        ));
      }

      // A segment spawned by runResumeChain() reports its own outcome and lets
      // the process driving the chain decide what happens next. Nesting a
      // chain inside every segment would keep one bootstrapped Drupal alive
      // per segment. An operator's own `--resume` is not a segment: it owns
      // the build like a fresh one does, and chains. It used to throw here,
      // so a 124k-entity site whose first build had been interrupted got one
      // segment per hand-launched command, each ending with this message.
      if (ResumeChainRunner::isSegment()) {
        throw new \RuntimeException(sprintf(
          'Memory limit reached after %d pages. The build is incomplete and the index has not been '
          . 'republished. Re-run `drush scolta:build --resume` to continue, or raise memory_limit.',
          $report->pagesProcessed,
        ));
      }

      $this->runResumeChain($options, $budget, $report, $orchestrator, $resolvedOutputDir);
      return;
    }

    throw new \RuntimeException('PHP indexer failed: ' . ($report->error ?? 'unknown'));
  }

  /**
   * Resolve a --entity-ids value to the IDs the build can actually index.
   *
   * Keeps the subset of the requested IDs that names a published entity, in
   * ascending ID order — the same publishability rule gather() applies to the
   * whole corpus. Everything else — a malformed token, an ID that does not
   * exist, an unpublished entity — is reported in one notice rather than
   * silently producing a smaller index than the operator asked for.
   *
   * @param string $entityType
   *   The entity type the IDs belong to.
   * @param string $raw
   *   The comma-delimited option value.
   *
   * @return string[]
   *   The published entity IDs, possibly empty.
   */
  private function resolveEntityIds(string $entityType, string $raw): array {
    $requested = array_values(array_unique(array_filter(
      array_map('trim', explode(',', $raw)),
      static fn(string $id): bool => $id !== '',
    )));

    $numeric = array_filter($requested, 'ctype_digit');
    $published = array_map('strval', $this->contentGatherer->publishedIds($entityType, array_values($numeric)));

    $skipped = array_diff($requested, $published);
    if (!empty($skipped)) {
      $this->logger()->notice('Entity IDs that could not be loaded (missing or unpublished): {ids}.', [
        'ids' => implode(', ', $skipped),
      ]);
    }

    return $published;
  }

  /**
   * Drive resume segments to completion, in the foreground.
   *
   * This used to be `exec('drush … --resume &')`: the command returned in
   * seconds having indexed nothing, exited 0, and left a detached chain of
   * about twenty processes to decide the real outcome with nobody reading the
   * result. The process that was asked to build the index now owns whether
   * it exists. The loop is scolta-php's ResumeChainRunner, via the shared
   * IndexBuildRunner; this supplies the options every segment must repeat.
   *
   * @param array $options
   *   The original command options.
   * @param \Tag1\Scolta\Index\MemoryBudget $budget
   *   Memory budget to pass to each segment.
   * @param \Tag1\Scolta\Index\StatusReport $firstReport
   *   The report from the segment that ran in this process.
   * @param \Tag1\Scolta\Index\IndexBuildOrchestrator $orchestrator
   *   The orchestrator that ran it.
   * @param string $outputDir
   *   Resolved index output directory.
   *
   * @throws \RuntimeException
   *   When the chain stalls, exceeds its segment budget, or fails.
   */
  private function runResumeChain(array $options, MemoryBudget $budget, StatusReport $firstReport, IndexBuildOrchestrator $orchestrator, string $outputDir): void {
    $repeat = [];
    foreach (['entity-type', 'bundle', 'entity-ids', 'chunk-size'] as $name) {
      if (isset($options[$name]) && $options[$name] !== '') {
        $repeat[$name] = (string) $options[$name];
      }
    }
    // --force must survive segmentation: an unforced segment serves any
    // entity whose changed timestamp matches the manifest from cached
    // references, and the manifest still holds the previous build's entries
    // for the whole corpus because pruneAndSave() only runs at end-of-build,
    // which the aborting parent never reached. Without this, a forced build
    // big enough to segment silently degrades to incremental for its tail.
    if (!empty($options['force'])) {
      $repeat['force'] = TRUE;
    }

    $report = $this->runner->resumeChain($orchestrator->coordinator()->buildState(), $firstReport, $budget, $this->logger(), $this->runSegmentProcess(...), $repeat);
    if (!$report->success) {
      throw new \RuntimeException($report->error ?? 'The resume chain failed.');
    }
    $this->confirmChainComplete($outputDir, TRUE);
  }

  /**
   * The orchestrator a build runs through.
   *
   * A seam: a kernel test overrides it to inject a memory-pressure probe, the
   * only way to reach the yield path without a corpus that fills the heap.
   */
  protected function orchestrator(string $stateDir, string $outputDir, string $language): IndexBuildOrchestrator {
    return new IndexBuildOrchestrator($stateDir, $outputDir, NULL, $language);
  }

  /**
   * Run a child `scolta:build` segment in the foreground; return its exit code.
   *
   * Protected so a kernel test can observe the segment instead of running a
   * drush that would run against the wrong database.
   *
   * @param array<string, mixed> $options
   *   The segment's command options.
   * @param array<string, string> $env
   *   Environment variables to set for the child.
   */
  protected function runSegmentProcess(array $options, array $env): int {
    return $this->runner->runDrush('scolta:build', $options, $env);
  }

  /**
   * Fail unless a usable index is actually on disk.
   *
   * @throws \RuntimeException
   *   When no complete index was published.
   */
  private function assertIndexUsable(string $outputDir): void {
    IndexBuildOrchestrator::verifyIndexComplete($outputDir);
  }

  /**
   * Confirm an index built across several processes, counting what is on disk.
   *
   * The page count belongs to whichever process finished the work, and this
   * one only ran a segment of it, so it reports the fragments actually
   * published rather than repeating its own partial figure as if it were the
   * total.
   */
  private function confirmChainComplete(string $outputDir, bool $chained): void {
    $this->assertIndexUsable($outputDir);

    $fragments = glob($outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
    $generation = $this->state->get('scolta.generation', 0);
    $this->state->set('scolta.generation', $generation + 1);
    $this->logger()->success('Index built: {pages} pages on disk{via}.', [
      'pages' => count($fragments),
      'via' => $chained ? ' (completed across resume segments)' : ' (finalized in a fresh process)',
    ]);
    $this->cacheTagsInvalidator->invalidateTags(['scolta_search_index']);
  }

  /**
   * Announce a completed build and invalidate the search caches.
   */
  private function reportBuildSuccess($report, string $outputDir): void {
    $this->assertIndexUsable($outputDir);

    $generation = $this->state->get('scolta.generation', 0);
    $this->state->set('scolta.generation', $generation + 1);
    $this->logger()->success('Index built: {pages} pages in {time}s ({mem} peak RAM).', [
      'pages' => $report->pagesProcessed,
      'time' => $report->durationSeconds,
      'mem' => $report->peakMemoryMb(),
    ]);
    $this->cacheTagsInvalidator->invalidateTags(['scolta_search_index']);
  }

  /**
   * Spawn drush scolta:finalize in a fresh PHP process.
   *
   * After large-corpus PHP indexing the heap is too fragmented to run
   * the merge in-process. This spawns a child drush command so the
   * merge starts with a clean heap.
   */
  private function spawnFinalize(string $stateDir, string $outputDir, int $budgetBytes): void {
    $this->logger()->notice('Running scolta:finalize in a fresh process.');
    $exitCode = $this->runner->runDrush('scolta:finalize', [
      'state-dir' => $stateDir,
      'output-dir' => $outputDir,
      'memory-budget' => round($budgetBytes / 1_048_576) . 'M',
    ], []);

    if ($exitCode !== 0) {
      throw new \RuntimeException(sprintf(
        'scolta:finalize exited with code %d, so the merge did not complete and no index was published. '
        . 'Re-run `drush scolta:finalize` once memory pressure has eased.',
        $exitCode,
      ));
    }
  }

  /**
   * Resolve a stream-wrapper URI or plain path to an absolute filesystem path.
   */
  private function resolvePath(string $uri): string {
    return $this->runner->resolvePath($uri);
  }

  /**
   * Resolve the build directory with private:// fallback.
   *
   * Falls back to public://scolta-build when the configured directory uses
   * private:// and the private file system is not configured on this site.
   */
  private function resolveBuildDir(string $uri): string {
    $resolved = $this->resolvePath($uri);
    if ($resolved === $uri && str_starts_with($uri, 'private://')) {
      $this->logger()->notice('Private file system not configured; using public://scolta-build for index storage.');
      $publicBase = $this->resolvePath('public://');
      if ($publicBase !== 'public://') {
        return $publicBase . '/scolta-build';
      }
    }
    return $resolved;
  }

  /**
   * Queue one full index rebuild for the next `drush queue:run scolta_rebuild`.
   *
   * For operators and deploy scripts. One waiting request is enough — the
   * worker folds everything in the queue into one build — so a queue that
   * already holds an item gets nothing added.
   */
  #[CLI\Command(name: 'scolta:request-build', aliases: ['srb'])]
  #[CLI\Usage(name: 'scolta:request-build', description: 'Queue a full rebuild; the queue:run cron tick runs it')]
  public function requestBuild(): void {
    $queue = $this->queueFactory->get(ScoltaRebuildWorker::QUEUE_NAME);
    if ($queue->numberOfItems() > 0) {
      $this->logger()->notice('A rebuild request is already waiting in the scolta_rebuild queue; nothing was added.');
      return;
    }
    $queue->createItem(['type' => 'request-build']);
    $this->logger()->success('Queued a full index rebuild. The next `drush queue:run scolta_rebuild` tick runs it.');
  }

  /**
   * Merge committed index chunks into the final Pagefind-compatible index.
   *
   * Use this after `scolta:build` exits with "merge deferred" on large
   * corpora where the PHP heap is too fragmented to merge in-process.
   * The chunks must already be committed to the build state directory.
   */
  #[CLI\Command(name: 'scolta:finalize', aliases: ['sf'])]
  #[CLI\Option(name: 'state-dir', description: 'Build state directory')]
  #[CLI\Option(name: 'output-dir', description: 'Output directory for the final index')]
  #[CLI\Option(name: 'memory-budget', description: 'Memory profile or byte value')]
  public function finalize(
    array $options = [
      'state-dir' => '',
      'output-dir' => '',
      'memory-budget' => NULL,
    ],
  ): void {
    $config = $this->configFactory->get('scolta.settings');

    $resolvedOutputDir = $options['output-dir'] ?: $this->resolvePath(
      $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind'
    );
    $resolvedStateDir = $options['state-dir'] ?: $this->resolveBuildDir(
      $config->get('pagefind.build_dir') ?? 'public://scolta-build'
    );

    $budget = $this->runner->memoryBudget(isset($options['memory-budget']) ? (string) $options['memory-budget'] : NULL);

    $this->logger()->notice('Finalizing index: merging chunks from {state} into {out}', [
      'state' => $resolvedStateDir,
      'out'   => $resolvedOutputDir,
    ]);

    $language     = $config->get('ai_languages')[0] ?? 'en';
    $orchestrator = new IndexBuildOrchestrator($resolvedStateDir, $resolvedOutputDir, NULL, $language);
    $report       = $orchestrator->finalize($budget, $this->logger());

    if ($report->success) {
      $generation = $this->state->get('scolta.generation', 0);
      $this->state->set('scolta.generation', $generation + 1);
      $this->logger()->success('Index finalized: {pages} pages in {time}s ({mem} peak RAM).', [
        'pages' => $report->pagesProcessed,
        'time' => $report->durationSeconds,
        'mem' => $report->peakMemoryMb(),
      ]);
      $this->cacheTagsInvalidator->invalidateTags(['scolta_search_index']);
    }
    else {
      $this->logger()->error('Finalize failed: {error}', ['error' => $report->error ?? 'unknown']);
    }
  }

  /**
   * Pre-resolve and cache all prompt templates.
   *
   * Stores resolved prompts in Drupal's cache so API endpoints can
   * read them without resolving on every request.
   */
  private function cacheResolvedPrompts(): void {
    $config = $this->aiService->getConfig();
    $siteName = $config->siteName;
    $siteDescription = $config->siteDescription;

    $prompts = [
      'expand_query' => DefaultPrompts::resolve(DefaultPrompts::EXPAND_QUERY, $siteName, $siteDescription),
      'summarize' => DefaultPrompts::resolve(DefaultPrompts::SUMMARIZE, $siteName, $siteDescription),
      'follow_up' => DefaultPrompts::resolve(DefaultPrompts::FOLLOW_UP, $siteName, $siteDescription),
    ];

    $cacheTtl = $config->cacheTtl > 0 ? $config->cacheTtl : 2592000;
    foreach ($prompts as $name => $resolved) {
      $this->cache->set("scolta.prompt.{$name}", $resolved, time() + $cacheTtl);
    }

    $this->logger()->success('Cached resolved prompts for: ' . implode(', ', array_keys($prompts)));
  }

  /**
   * Delete retired index directories left by index builds.
   *
   * Publishing a new index renames the previous one to a `.scolta-trash-*`
   * directory next to `pagefind/` and returns — deleting it inline, or
   * sweeping right after publishing, made a finished build look hung for
   * minutes to hours on NFS-backed file storage. This command and the cron
   * sweep are what delete that trash; the next build's bounded pre-build
   * sweep is the backstop for a site running neither.
   * Always safe: the live index is never touched, and a directory that
   * cannot be deleted is left for the next run.
   *
   * A stale `.scolta-old` left by a swap that died partway is retired to
   * trash first, so it is cleaned up here too. `.scolta-new` and
   * `.scolta-building` are left alone: they may belong to a build that is
   * running right now.
   */
  #[CLI\Command(name: 'scolta:cleanup', aliases: ['scu'])]
  #[CLI\Option(name: 'dry-run', description: 'List the directories that would be deleted without deleting anything.')]
  #[CLI\Usage(name: 'scolta:cleanup', description: 'Delete retired index directories')]
  #[CLI\Usage(name: 'scolta:cleanup --dry-run', description: 'Show what would be deleted')]
  public function cleanup(array $options = ['dry-run' => FALSE]): void {
    $config = $this->configFactory->get('scolta.settings');
    $configuredDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $outputDir = $this->resolvePath($configuredDir);
    // resolvePath() hands back the URI it was given when the wrapper is
    // missing or has no real path — a private:// output dir on a site with no
    // private file system, say. Handing that to RetiredIndexTrash gets an
    // `InvalidArgumentException: Stream wrappers are not allowed in file paths`
    // out of scolta-php's FilesystemDriver, which names neither the setting at
    // fault nor its value. hook_cron() already refuses to run on a directory it
    // could not resolve; this is the same refusal, said out loud.
    //
    // The test is "resolution changed nothing", not "the result has a
    // scheme": a wrapper may resolve to another stream (KernelTestBase's
    // public:// resolves to vfs://). Only the unresolved value is a problem.
    if ($outputDir === $configuredDir && str_contains($configuredDir, '://')) {
      throw new \RuntimeException(sprintf(
        'Could not resolve pagefind.output_dir (%s) to a filesystem path, so no retired index directory can be found or deleted. Check that the stream wrapper it names is available on this site.',
        $configuredDir
      ));
    }
    $outputDir = rtrim($outputDir, '/');
    // The orchestrator publishes to <output_dir>/pagefind and normalizes a
    // config value that already carries the suffix; trash sits beside the
    // published directory, so mirror that normalization here.
    if (str_ends_with($outputDir, '/pagefind')) {
      $outputDir = substr($outputDir, 0, -strlen('/pagefind'));
    }

    $trash = new RetiredIndexTrash(new FilesystemDriver(), $outputDir);

    // A `.scolta-old` corpse from an interrupted swap becomes trash too. If a
    // swap is retiring the previous index this very moment, taking the
    // directory out from under it is harmless — it was headed to trash anyway.
    //
    // Not under --dry-run: retire() renames the directory, and an operator
    // running a dry run to decide whether to run the real thing has been told
    // the option deletes nothing. It is reported as pending instead.
    $oldDir = $outputDir . '/.scolta-old';
    $oldDirPending = file_exists($oldDir);
    if ($oldDirPending && !$options['dry-run']) {
      $trash->retire($oldDir);
    }

    $dirs = $trash->trashDirs();
    if ($oldDirPending && $options['dry-run']) {
      $dirs[] = $oldDir;
    }

    if ($dirs === []) {
      $this->logger()->success('No retired index directories to delete.');
      return;
    }

    if ($options['dry-run']) {
      $this->logger()->notice("Would delete:\n  " . implode("\n  ", $dirs));
      return;
    }

    $trash->sweep($this->logger());
    $remaining = count($trash->trashDirs());
    if ($remaining === 0) {
      $this->logger()->success(sprintf('Deleted %d retired index director%s.', count($dirs), count($dirs) === 1 ? 'y' : 'ies'));
    }
    else {
      $this->logger()->warning(sprintf('%d retired index director%s could not be deleted; run scolta:cleanup again later.', $remaining, $remaining === 1 ? 'y' : 'ies'));
    }
  }

  /**
   * Clear Scolta caches (expansion and summary).
   *
   * Scolta shares the cache.default bin with every other module, so wiping
   * the bin is off limits. AI expansion/summary entries embed the
   * scolta.generation counter in their cache key, so bumping the generation
   * orphans all existing entries; the resolved-prompt entries use known
   * fixed keys and are deleted directly.
   */
  #[CLI\Command(name: 'scolta:clear-cache', aliases: ['scc'])]
  public function clearCache(): void {
    $generation = $this->state->get('scolta.generation', 0);
    $this->state->set('scolta.generation', $generation + 1);

    $this->cache->deleteMultiple([
      'scolta.prompt.expand_query',
      'scolta.prompt.summarize',
      'scolta.prompt.follow_up',
    ]);

    $this->logger()->success('Scolta caches cleared (generation bumped, resolved prompts deleted).');
  }

  /**
   * Verify Scolta dependencies and configuration.
   *
   * Checks PHP version, runtime requirements, and AI key.
   */
  #[CLI\Command(name: 'scolta:check-setup', aliases: ['scs'])]
  public function checkSetup(): void {
    $results = SetupCheck::run(
      aiApiKey: $this->aiService->getApiKey(),
      // The AI-key row names the source and reports an overridden Amazee.ai
      // credential, from the same resolution the settings form and /health
      // read (scolta-php#252).
      resolvedKey: $this->aiService->resolveApiKey(),
    );

    foreach ($results as $r) {
      $icon = match ($r['status']) {
        'pass' => '[OK]',
        'warn' => '[!!]',
        'fail' => '[FAIL]',
        default => '[??]',
      };
      $method = match ($r['status']) {
        'fail' => 'error',
        'warn' => 'warning',
        default => 'notice',
      };
      $this->logger()->$method("{$icon} {$r['name']}: {$r['message']}");
    }

    $exit = SetupCheck::exitCode($results);
    if ($exit === 0) {
      $this->logger()->success('All critical checks passed.');
    }
    else {
      $this->logger()->error('One or more critical checks failed.');
    }
  }

  /**
   * Show Scolta status: build directory, index, AI provider, cache.
   *
   * Returns the structured data rather than printing it, so Drush's output
   * formatters offer --format=json and friends; the default stays YAML so the
   * section groupings survive machine consumption on stdout — logger lines
   * flattened the structure and went to stderr.
   */
  #[CLI\Command(name: 'scolta:status', aliases: ['sst'])]
  #[CLI\Usage(name: 'scolta:status --format=json', description: 'Emit the same report as JSON')]
  public function status(array $options = ['format' => 'yaml']): UnstructuredListData {
    $config = $this->configFactory->get('scolta.settings');
    $status = [];

    // Build directory.
    $buildDirConfig = $config->get('pagefind.build_dir') ?? 'public://scolta-build';
    $resolvedBuildDir = $this->resolveBuildDir($buildDirConfig);
    $status['build_directory'] = [
      'configured' => $buildDirConfig,
      'resolved' => $resolvedBuildDir,
      'exists' => is_dir($resolvedBuildDir),
    ];

    // Anything in flight: a queued rebuild, and the manifest a running or
    // half-finished build leaves in the state directory. All of it is a few
    // small file reads plus one queue count, so status can afford it.
    $status['build'] = [
      'queued_items' => (int) $this->queueFactory->get(ScoltaRebuildWorker::QUEUE_NAME)->numberOfItems(),
      // What the build is doing: idle, gathering, merging, publishing, or
      // interrupted when the manifest says 'building' but no live process
      // holds the lock (a segment that died, waiting for a resume).
      'activity' => 'idle',
    ];
    if (is_dir($resolvedBuildDir)) {
      $buildState = new BuildState($resolvedBuildDir);
      if ($buildState->shouldResume() !== NULL) {
        $phase = $buildState->phase() ?? BuildState::PHASE_GATHERING;
        $status['build']['activity'] = $buildState->isRunning() ? $phase : 'interrupted';
        $status['build'] += [
          'started' => $buildState->getStartTime(),
          'segment' => $buildState->segment(),
          'pages_processed' => $buildState->getPagesProcessed(),
        ];
        // Chunks committed over the chunk count the pre-gather entity total
        // implies. Documents that produce no page make that total an
        // over-estimate, so it describes only the gather and is left out once
        // the build has moved on to merging.
        if ($phase === BuildState::PHASE_GATHERING) {
          $status['build']['progress'] = round($buildState->getProgress() * 100, 1) . '%';
        }
        $lock = $buildState->lockDiagnostics();
        if ($lock !== NULL) {
          $status['build']['lock'] = [
            'pid' => $lock['pid'],
            'host' => $lock['host'],
            // A live build rewrites its lock record every
            // BuildState::HEARTBEAT_INTERVAL_SECONDS; once the last one is
            // STALE_LOCK_SECONDS old the holder is presumed dead.
            'heartbeat_age_seconds' => $lock['age_seconds'],
            'stale_after_seconds' => BuildState::STALE_LOCK_SECONDS,
            'stale' => $lock['stale'],
          ];
        }
        // Why the last run that reported stopped. 'memory_abort' means it
        // yielded on purpose and wants another segment; any other error means
        // the chain stopped and nothing will resume the build on its own. A
        // segment killed outright (OOM killer) records nothing, so this can
        // describe an earlier segment — hence recorded_at, to compare against
        // the build's own start time.
        $outcome = $buildState->readOutcome();
        if ($outcome !== NULL) {
          $status['build']['last_segment'] = [
            'success' => $outcome['success'],
            'error' => $outcome['error'],
            'pages_processed' => $outcome['pages_processed'],
            'recorded_at' => $outcome['recorded_at'],
          ];
        }
      }
    }

    // Pagefind index.
    $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $resolvedDir = $this->resolvePath($outputDir);
    $location = $this->indexLocator->locate($resolvedDir);
    $status['pagefind_index'] = ['path' => $outputDir];
    if ($location !== NULL) {
      // Read the count from pagefind-entry.json rather than counting fragment
      // files: on NFS with a six-figure corpus that glob() is minutes-slow
      // (see PagefindBuilder::getStatus()), and status only needs a number.
      $pageCount = $this->indexLocator->pageCount($location);
      if ($pageCount === NULL) {
        $pageCount = $this->indexLocator->countFragments($location);
      }
      $mtime = filemtime($location['indexFile']);
      $status['pagefind_index']['built'] = TRUE;
      $status['pagefind_index']['pages'] = $pageCount;
      $status['pagefind_index']['last_built'] = $mtime ? date('Y-m-d H:i:s', $mtime) : NULL;
    }
    else {
      $status['pagefind_index']['built'] = FALSE;
    }

    // AI provider. Routing only goes through the Drupal AI module when the
    // admin explicitly selected 'drupal_ai' AND the module is installed —
    // mirror that here instead of reporting on module presence alone.
    //
    // No coalescing to a provider nobody chose: an empty value means AI is
    // off, and a status command has to report that rather than name Anthropic.
    $provider = $config->get('ai_provider') ?? '';
    if ($provider === '') {
      $providerRow = [
        'provider' => NULL,
        'note' => 'None selected — AI features are off (search is unaffected).',
      ];
    }
    elseif ($provider === 'drupal_ai' && $this->aiService->hasDrupalAiModule()) {
      $providerRow = ['provider' => 'drupal_ai', 'routing' => 'Drupal AI module'];
    }
    elseif ($provider === 'drupal_ai') {
      $providerRow = [
        'provider' => 'drupal_ai',
        'routing' => 'built-in client',
        'note' => 'drupal_ai selected but AI module not installed — falling back to built-in client.',
      ];
    }
    else {
      $providerRow = ['provider' => $provider, 'routing' => 'built-in client'];
    }
    // The source and the description come from the same resolution the client
    // uses, so `status` cannot claim Amazee.ai while an explicit key serves
    // every request (scolta-php#252).
    $resolvedKey = $this->aiService->resolveApiKey();
    $providerRow['api_key'] = [
      'source' => $resolvedKey->source->value,
      'description' => $resolvedKey->describe(),
    ];

    // The same cache marker /health reads via HealthChecker, so a provider
    // whose stored credentials are being rejected is reported here too rather
    // than only surfacing once someone happens to check /health. A cached
    // marker, not a live probe — see KeyExpiryRecovery and
    // docs/HEALTH_REFERENCE.md in scolta-php.
    $cacheDriver = new DrupalCacheDriver($this->cache);
    $authFailing = (bool) $cacheDriver->get(KeyExpiryRecovery::CACHE_KEY_AUTH_FAILURE);
    $providerRow['auth_failing'] = $authFailing;
    $providerRow['auth_failing_since'] = $authFailing
      ? (($since = KeyExpiryRecovery::readFailureTimestamp($cacheDriver)) !== NULL ? date('c', $since) : NULL)
      : NULL;

    $status['ai_provider'] = $providerRow;

    // Generation counter.
    $status['cache'] = [
      'generation' => $this->state->get('scolta.generation', 0),
    ];

    return new UnstructuredListData($status);
  }

  /**
   * Show what the built index holds for an entity.
   *
   * A fragment is the index's own copy of a page: the URL, the indexed text,
   * the filter values and the metadata the result list renders. Reading one
   * answers "is this entity in the index, and with what?" without a rebuild
   * and without the browser.
   *
   * Fragment files are named by a content hash, not by URL, so finding the
   * one for an entity means decompressing fragments until its URL turns up.
   * Fine for debugging one page; slow on a six-figure corpus over NFS.
   */
  #[CLI\Command(name: 'scolta:inspect', aliases: ['sin'])]
  #[CLI\Argument(name: 'entityType', description: 'Entity type ID, e.g. node')]
  #[CLI\Argument(name: 'entityId', description: 'Entity ID')]
  #[CLI\Usage(name: 'scolta:inspect node 123', description: 'Show the fragment indexed for that node, and its translations')]
  #[CLI\Usage(name: 'scolta:inspect node 123 --format=json', description: 'The same, as JSON')]
  public function inspect(string $entityType, string $entityId, array $options = ['format' => 'yaml']): UnstructuredListData {
    $config = $this->configFactory->get('scolta.settings');
    $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $location = $this->indexLocator->locate($this->resolvePath($outputDir));
    if ($location === NULL) {
      throw new \RuntimeException(sprintf('No built index under %s. Run drush scolta:build first.', $outputDir));
    }

    if (!$this->entityTypeManager->hasDefinition($entityType)) {
      throw new \RuntimeException(sprintf('No such entity type: %s.', $entityType));
    }
    $entity = $this->entityTypeManager->getStorage($entityType)->load($entityId);
    if ($entity === NULL) {
      throw new \RuntimeException(sprintf('No %s with ID %s.', $entityType, $entityId));
    }
    // The URL is the only join between an entity and its fragment: nothing in
    // the fragment carries the entity ID. Matching is anchored on the end of
    // the URL rather than str_contains() so that /node/123 does not match
    // /node/1234, while a translation under a language prefix still does.
    $url = $entity->toUrl()->toString();

    $matches = [];
    foreach ($this->indexLocator->fragmentFiles($location) as $file) {
      $fragment = $this->readFragment($file);
      if ($fragment === NULL) {
        continue;
      }
      if (($fragment['url'] ?? '') === $url || str_ends_with($fragment['url'] ?? '', $url)) {
        $matches[basename($file)] = $fragment;
      }
    }

    if ($matches === []) {
      $this->logger()->warning(dt('Nothing in the index is indexed at @url. It may not be indexed, or the index may predate it.', ['@url' => $url]));
    }
    return new UnstructuredListData($matches);
  }

  /**
   * Decode one fragment file: gzipped "pagefind_dcd" + JSON.
   *
   * @return array|null
   *   The decoded fragment, or NULL when the file is not one (a stray file in
   *   the fragment directory, or a truncated write).
   */
  private function readFragment(string $file): ?array {
    $raw = @gzdecode((string) file_get_contents($file));
    if ($raw === FALSE || !str_starts_with($raw, 'pagefind_dcd')) {
      return NULL;
    }
    $decoded = json_decode(substr($raw, strlen('pagefind_dcd')), TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

}
