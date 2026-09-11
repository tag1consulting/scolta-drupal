<?php

declare(strict_types=1);

namespace Drupal\scolta\Commands;

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
use GuzzleHttp\ClientInterface;
use Symfony\Component\Yaml\Yaml;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntentFactory;
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
 * Scolta:export  -- Export CMS content as HTML files.
 * scolta:build   -- Run export, pagefind CLI, deploy.
 * scolta:request-build -- Queue one full rebuild for the queue:run cron tick.
 * scolta:clear-cache -- Clear expansion/summary caches.
 * scolta:cleanup -- Delete retired index (.scolta-trash-*) directories.
 * scolta:download-pagefind -- Download the Pagefind binary.
 */
class ScoltaCommands extends DrushCommands {

  /**
   * Constructs a ScoltaCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
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
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
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
  ) {
    parent::__construct();
  }

  /**
   * Export content as minimal HTML files for Pagefind indexing.
   *
   * Queries Drupal entities and delegates content cleaning and HTML
   * generation to the shared Tag1\Scolta\Export\ContentExporter.
   */
  #[CLI\Command(name: 'scolta:export', aliases: ['se'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type(s) to export, comma-separated')]
  #[CLI\Option(name: 'bundle', description: 'Bundle/content type to export')]
  #[CLI\Option(name: 'output-dir', description: 'Output directory for HTML files')]
  #[CLI\Usage(name: 'scolta:export node --bundle=article', description: 'Export all published articles')]
  #[CLI\Usage(name: 'scolta:export node --bundle=page --output-dir=/var/www/html/pagefind-site', description: 'Export pages to specific directory')]
  public function export(
    string $entity_type = '',
    array $options = ['bundle' => '', 'output-dir' => ''],
  ): void {
    $config = $this->configFactory->get('scolta.settings');
    $outputDir = $options['output-dir'] ?: $this->defaultExportDir();
    $bundle = $options['bundle'] ?: '';
    $siteName = $config->get('site_name') ?: ($this->configFactory->get('system.site')->get('name') ?? '');

    $exporter = new ContentExporter($outputDir);
    $exporter->prepareOutputDir();

    foreach ($this->runner->entityTypes($entity_type) as $entityType) {
      foreach ($this->contentGatherer->gather($entityType, $bundle, $siteName) as $item) {
        $exporter->export($item);
      }
    }

    $stats = $exporter->getStats();
    $this->logger()->success("Exported {$stats['exported']} entities to {$outputDir}/");
    if ($stats['skipped'] > 0) {
      $this->logger()->notice("Skipped {$stats['skipped']} entities with insufficient content.");
    }
  }

  /**
   * Build the Pagefind search index.
   *
   * Runs export -> pagefind CLI -> copies search page to docroot.
   * When using the PHP indexer, content is processed in-memory without
   * exporting HTML files or invoking the Pagefind binary.
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
  #[CLI\Option(name: 'entity-ids', description: 'Comma-separated entity IDs to index. Scopes the build; see the help text above. Unloadable IDs are logged and skipped. PHP indexer only; --bundle is ignored')]
  #[CLI\Option(name: 'output-dir', description: 'Export directory for the binary indexer')]
  #[CLI\Option(name: 'skip-pagefind', description: 'Export content only, skip Pagefind build')]
  #[CLI\Option(name: 'indexer', description: 'Indexer mode: php, binary, or auto')]
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
      'output-dir' => '',
      'skip-pagefind' => FALSE,
      'indexer' => '',
      'force' => FALSE,
      'memory-budget' => NULL,
      'chunk-size' => NULL,
      'resume' => FALSE,
      'restart' => FALSE,
      'reset-ledger' => FALSE,
    ],
  ): void {
    $config = $this->configFactory->get('scolta.settings');

    // Resolve indexer mode: CLI option overrides config.
    $indexerMode = $options['indexer'] ?: ($config->get('indexer') ?: 'auto');
    if (!in_array($indexerMode, ['auto', 'php', 'binary'], TRUE)) {
      throw new \RuntimeException(sprintf('Invalid indexer "%s". Must be one of: auto, php, binary.', $indexerMode));
    }

    if ($indexerMode === 'auto') {
      $indexerMode = $this->resolveAutoIndexer($config);
    }

    if ($indexerMode === 'php') {
      $this->buildWithPhpIndexer($options, $config, (bool) $options['force']);
    }
    else {
      // The binary pipeline walks the whole corpus through scolta:export and
      // has no ID-scoped entry point.
      if (!empty($options['entity-ids'])) {
        throw new \RuntimeException('--entity-ids is only supported by the PHP indexer. Re-run with --indexer=php.');
      }
      if (!empty($options['reset-ledger'])) {
        throw new \RuntimeException('--reset-ledger is only supported by the PHP indexer. Re-run with --indexer=php.');
      }
      $this->buildWithBinary($options);
    }

    // Cache resolved prompts regardless of indexer mode.
    $this->logger()->notice('Caching resolved prompts...');
    $this->cacheResolvedPrompts();
  }

  /**
   * Resolve 'auto' indexer mode.
   *
   * Auto always uses the PHP indexer — it works on all PHP hosting
   * environments without exec() or Node.js, uses less memory, and
   * supports fast incremental re-indexing. Set indexer: binary to
   * use the Pagefind binary explicitly.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   *
   * @return string
   *   Always 'php'.
   */
  private function resolveAutoIndexer($config): string {
    $this->logger()->notice('Auto-detected indexer: php (default).');
    return 'php';
  }

  /**
   * Build using the existing binary pipeline (export HTML + run Pagefind).
   */
  private function buildWithBinary(array $options): void {
    $this->logger()->notice('Step 1: Exporting content...');
    $exportDir = $options['output-dir'] ?: $this->defaultExportDir();
    $this->export($options['entity-type'], [
      'bundle' => $options['bundle'],
      'output-dir' => $exportDir,
    ]);

    if ($options['skip-pagefind']) {
      $this->logger()->success('Export complete. Skipped Pagefind build (--skip-pagefind).');
      return;
    }

    $this->logger()->notice('Step 2: Building Pagefind index (binary)...');
    $config = $this->configFactory->get('scolta.settings');
    $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    if (str_contains($outputDir, '://')) {
      try {
        $resolvedOutputDir = $this->streamWrapperManager
          ->getViaUri($outputDir)->realpath() ?: $outputDir;
      }
      catch (\Exception $e) {
        $resolvedOutputDir = $outputDir;
      }
    }
    else {
      $resolvedOutputDir = $outputDir;
    }
    $this->runPagefind($exportDir, $resolvedOutputDir . '/pagefind');
  }

  /**
   * Where the binary pipeline exports HTML when no --output-dir is given.
   *
   * A scratch directory under the configured build dir, so it lands beside
   * the rest of the build state instead of at a path hardcoded for one host.
   */
  private function defaultExportDir(): string {
    $config = $this->configFactory->get('scolta.settings');
    return $this->resolveBuildDir($config->get('pagefind.build_dir') ?? 'public://scolta-build') . '/export';
  }

  /**
   * Build using the PHP indexer (in-memory, no Pagefind binary needed).
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
    $this->logger()->notice('Gathering content (PHP indexer): {count} entities.', ['count' => $totalCount]);

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
    $report = $this->runner->runSegment($orchestrator, $resolvedOutputDir, $intent, $entityTypes, $resumeCursors, $this->logger(), $reporter, $bundle, $entityIds, $force);

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
   * Rebuild the Pagefind index from existing exported HTML files.
   *
   * Skips the content export step — runs only the Pagefind CLI.
   * Useful after config changes or Pagefind upgrades.
   */
  #[CLI\Command(name: 'scolta:rebuild-index', aliases: ['sri'])]
  #[CLI\Option(name: 'source-dir', description: 'Source directory with exported HTML files')]
  #[CLI\Option(name: 'output-dir', description: 'Pagefind output directory')]
  public function rebuildIndex(
    array $options = [
      'source-dir' => '',
      'output-dir' => '',
    ],
  ): void {
    $config = $this->configFactory->get('scolta.settings');
    $sourceDir = $options['source-dir'] ?: $this->defaultExportDir();
    $outputDir = $options['output-dir'] ?: $this->resolvePath($config->get('pagefind.output_dir') ?? 'public://scolta-pagefind') . '/pagefind';
    $this->logger()->notice('Rebuilding Pagefind index from existing HTML files...');
    $this->runPagefind($sourceDir, $outputDir);
  }

  /**
   * Run the Pagefind CLI to build a search index.
   */
  private function runPagefind(string $sourceDir, string $outputDir): void {
    $config = $this->configFactory->get('scolta.settings');
    $resolver = new PagefindBinary(
      configuredPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );

    $binary = $resolver->resolve();
    if ($binary === NULL) {
      $status = $resolver->status();
      $this->logger()->error($status['message']);
      return;
    }

    $this->logger()->notice('Using Pagefind: {binary} (resolved via {via})', [
      'binary' => $binary,
      'via' => $resolver->resolvedVia(),
    ]);

    $cmd = $binary
      . ' --site ' . escapeshellarg($sourceDir)
      . ' --output-path ' . escapeshellarg($outputDir)
      . ' 2>&1';
    $result = NULL;
    $output = [];
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions,Drupal.Commenting.PostStatementComment,Drupal.Commenting.InlineComment,Drupal.Files.LineLength -- nosemgrep trails the call because semgrep reads it only there. exec runs the Pagefind CLI; paths are escapeshellarg-quoted and the binary comes from admin config.
    exec($cmd, $output, $result); // nosemgrep: php.lang.security.exec-use.exec-use
    foreach ($output as $line) {
      $this->logger()->notice($line);
    }
    if ($result !== 0) {
      $this->logger()->error('Pagefind build failed.');
      return;
    }

    // Increment generation counter to invalidate caches.
    $generation = $this->state->get('scolta.generation', 0);
    $this->state->set('scolta.generation', $generation + 1);

    $this->cacheTagsInvalidator->invalidateTags(['scolta_search_index']);
    $this->logger()->success('Index built successfully.');
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
   * directory next to `pagefind/` and sweeps trash right after publishing —
   * the old inline file-by-file deletion made a finished build look hung
   * for hours on NFS-backed file storage. This command and the cron sweep
   * are the backstops: they delete trash left by builds that died before
   * their own sweep and by the batch-UI indexing path, which never sweeps.
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
   * Checks PHP version, Pagefind binary, and AI key.
   */
  #[CLI\Command(name: 'scolta:check-setup', aliases: ['scs'])]
  public function checkSetup(): void {
    $config = $this->configFactory->get('scolta.settings');

    $results = SetupCheck::run(
      configuredBinaryPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT')
        ? DRUPAL_ROOT : getcwd(),
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
   * Show Scolta status: tracker, index, binary, AI provider.
   *
   * Emits YAML on stdout so the section groupings survive machine
   * consumption — logger lines flattened the structure and went to stderr.
   */
  #[CLI\Command(name: 'scolta:status', aliases: ['sst'])]
  public function status(): void {
    $config = $this->configFactory->get('scolta.settings');
    $status = [];

    // Search API index status.
    try {
      $indexes = $this->entityTypeManager
        ->getStorage('search_api_index')
        ->loadMultiple();
      $rows = [];
      foreach ($indexes as $index) {
        if ($index->getServerId() && str_contains($index->getServerId(), 'scolta')) {
          $tracker = $index->getTrackerInstance();
          $rows[] = [
            'label' => $index->label(),
            'status' => $index->status() ? 'enabled' : 'disabled',
            'indexed' => $tracker->getIndexedItemsCount(),
            'total' => $tracker->getTotalItemsCount(),
          ];
        }
      }
      $status['search_api'] = ['indexes' => $rows];
      if ($rows === []) {
        $status['search_api']['note'] = 'No Scolta index configured.';
      }
    }
    catch (\Exception $e) {
      $status['search_api'] = ['error' => 'Could not query Search API: ' . $e->getMessage()];
    }

    // Indexer selection and active state.
    $indexerSetting = $config->get('indexer') ?: 'auto';
    $status['indexer'] = ['configured' => $indexerSetting];
    if ($indexerSetting === 'binary') {
      // Only probe the binary when it's actually the active indexer:
      // PagefindBinary::status() runs up to five blocking exec() calls with
      // no timeout (configured path, project-local, `npx pagefind
      // --version`, bare `pagefind`, then a version() call), and on a
      // network-restricted host an npx resolution attempt can hang
      // indefinitely. When the indexer is php/auto that status is never
      // even displayed, so it isn't worth the risk.
      $resolver = new PagefindBinary(
        configuredPath: $config->get('pagefind.binary'),
        projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
      );
      $binaryStatus = $resolver->status();
      $status['indexer']['active'] = 'binary';
      $status['indexer']['binary'] = [
        'available' => $binaryStatus['available'],
        'message' => $binaryStatus['message'],
      ];
      if (!$binaryStatus['available']) {
        $status['indexer']['binary']['hint'] = 'To install: npm install -g pagefind  OR  drush scolta:download-pagefind';
      }
    }
    else {
      $status['indexer']['active'] = 'php';
    }

    // Build directory.
    $buildDirConfig = $config->get('pagefind.build_dir') ?? 'public://scolta-build';
    $resolvedBuildDir = $this->resolveBuildDir($buildDirConfig);
    $status['build_directory'] = [
      'configured' => $buildDirConfig,
      'resolved' => $resolvedBuildDir,
      'exists' => is_dir($resolvedBuildDir),
    ];

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

    $this->output()->writeln(Yaml::dump($status, 4, 2));
  }

  /**
   * Download the Pagefind binary for the current platform.
   *
   * Detects OS and architecture, fetches the latest release from GitHub,
   * and extracts the binary to the specified location.
   */
  #[CLI\Command(name: 'scolta:download-pagefind', aliases: ['sdp'])]
  #[CLI\Option(name: 'version', description: 'Pagefind version to download')]
  #[CLI\Option(name: 'dest', description: 'Destination directory for the binary')]
  #[CLI\Usage(name: 'scolta:download-pagefind', description: 'Download latest Pagefind binary')]
  #[CLI\Usage(name: 'scolta:download-pagefind --version=1.1.0 --dest=/usr/local/bin', description: 'Download specific version to specific directory')]
  public function downloadPagefind(
    array $options = ['version' => 'latest', 'dest' => ''],
  ): void {
    // Detect platform.
    $os = PHP_OS_FAMILY;
    $arch = php_uname('m');

    $platformMap = [
      'Darwin' => [
        'x86_64' => 'x86_64-apple-darwin',
        'arm64' => 'aarch64-apple-darwin',
      ],
      'Linux' => [
        'x86_64' => 'x86_64-unknown-linux-musl',
        'aarch64' => 'aarch64-unknown-linux-musl',
        'arm64' => 'aarch64-unknown-linux-musl',
      ],
      'Windows' => [
        'x86_64' => 'x86_64-pc-windows-msvc',
        'AMD64' => 'x86_64-pc-windows-msvc',
      ],
    ];

    if (!isset($platformMap[$os][$arch])) {
      $this->logger()->error("Unsupported platform: {$os} {$arch}");
      return;
    }

    $platform = $platformMap[$os][$arch];
    $version = $options['version'];
    $resolver = new PagefindBinary(
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );
    $dest = $options['dest'] ?: $resolver->downloadTargetDir();

    // Resolve latest version from GitHub API.
    if ($version === 'latest') {
      $this->logger()->notice('Fetching latest Pagefind release info from GitHub...');
      try {
        $response = $this->httpClient->request('GET', 'https://api.github.com/repos/CloudCannon/pagefind/releases/latest', [
          'headers' => [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'Scolta-Drupal',
          ],
          'timeout' => 15,
        ]);
        try {
          $releaseData = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $e) {
          $this->logger()->error('Failed to parse GitHub API response: ' . $e->getMessage());
          return;
        }
        $version = ltrim($releaseData['tag_name'] ?? '', 'v');
        if (empty($version)) {
          $this->logger()->error('Could not determine latest Pagefind version from GitHub.');
          return;
        }
      }
      catch (\Exception $e) {
        $this->logger()->error('Failed to fetch release info from GitHub: ' . $e->getMessage());
        return;
      }
    }

    $this->logger()->notice("Downloading Pagefind v{$version} for {$platform}...");

    $ext = ($os === 'Windows') ? 'zip' : 'tar.gz';
    $filename = "pagefind-v{$version}-{$platform}.{$ext}";
    $url = "https://github.com/CloudCannon/pagefind/releases/download/v{$version}/{$filename}";

    // Download the archive.
    $tempFile = sys_get_temp_dir() . '/' . $filename;
    try {
      $response = $this->httpClient->request('GET', $url, [
        'sink' => $tempFile,
        'timeout' => 120,
        'headers' => [
          'User-Agent' => 'Scolta-Drupal',
        ],
      ]);

      if ($response->getStatusCode() !== 200) {
        $this->logger()->error("Download failed with HTTP {$response->getStatusCode()}");
        return;
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Download failed: ' . $e->getMessage());
      return;
    }

    // Extract the binary.
    if (!is_dir($dest)) {
      $this->fileSystem->mkdir($dest, 0755, TRUE);
    }

    try {
      if ($ext === 'tar.gz') {
        $phar = new \PharData($tempFile);
        $phar->extractTo($dest, NULL, TRUE);
      }
      else {
        $zip = new \ZipArchive();
        if ($zip->open($tempFile) === TRUE) {
          $zip->extractTo($dest);
          $zip->close();
        }
        else {
          $this->logger()->error('Failed to open zip archive.');
          return;
        }
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Extraction failed: ' . $e->getMessage());
      return;
    }

    // Make binary executable on Unix.
    $binaryPath = rtrim($dest, '/') . '/pagefind';
    if ($os !== 'Windows' && file_exists($binaryPath)) {
      $this->fileSystem->chmod($binaryPath, 0755);
    }

    // Clean up temp file.
    if (file_exists($tempFile)) {
      $this->fileSystem->delete($tempFile);
    }

    $this->logger()->success("Pagefind v{$version} installed to {$dest}/");

    // Auto-update Drupal config to point to the downloaded binary.
    $editableConfig = $this->configFactory->getEditable('scolta.settings');
    $editableConfig->set('pagefind.binary', $binaryPath);
    $editableConfig->save();
    $this->logger()->notice('Drupal config updated: pagefind.binary = {path}', [
      'path' => $binaryPath,
    ]);

    // Verify the binary works.
    $output = [];
    $exitCode = NULL;
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions,Drupal.Commenting.PostStatementComment,Drupal.Commenting.InlineComment,Drupal.Files.LineLength -- nosemgrep trails the call because semgrep reads it only there. exec runs the binary this command just downloaded; the path is escapeshellarg-quoted.
    exec(escapeshellarg($binaryPath) . ' --version 2>&1', $output, $exitCode); // nosemgrep: php.lang.security.exec-use.exec-use
    if ($exitCode === 0) {
      $this->logger()->notice('Verified: ' . implode(' ', $output));
    }
    else {
      $this->logger()->warning('Binary was extracted but --version check failed. You may need to adjust your PATH or permissions.');
    }
  }

}
