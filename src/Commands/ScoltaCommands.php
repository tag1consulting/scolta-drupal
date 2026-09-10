<?php

declare(strict_types=1);

namespace Drupal\scolta\Commands;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Cache\DrupalCacheDriver;
use Drupal\scolta\Progress\DrushProgressReporter;
use Drupal\scolta\Service\IndexLocator;
use Drupal\scolta\Service\ScoltaAiService;
use Drupal\scolta\Service\ScoltaContentGatherer;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Utils\StringUtils;
use Symfony\Component\Yaml\Yaml;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Index\ResumeChainPolicy;
use Tag1\Scolta\Index\RetiredIndexTrash;
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
   * How many fresh processes a single build may use to get through the corpus.
   *
   * A bound rather than a target: each segment must commit pages the previous
   * one did not, so a build that is genuinely progressing finishes well inside
   * this, and one that is not fails with a message naming the limit instead of
   * spawning processes until someone notices.
   */
  private const MAX_RESUME_SEGMENTS = 50;

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
  #[CLI\Option(name: 'entity-type', description: 'Entity type(s) to index, comma-separated. Default: the configured entity_types (node unless configured otherwise)')]
  #[CLI\Option(name: 'bundle', description: 'Bundle to index. Scopes the build; see the help text above')]
  #[CLI\Option(name: 'entity-ids', description: 'Comma-separated entity IDs to index. Scopes the build; see the help text above. Unloadable IDs are logged and skipped. --bundle is ignored')]
  #[CLI\Option(name: 'force', description: 'Skip fingerprint check and force a full rebuild')]
  #[CLI\Option(name: 'memory-budget', description: 'Memory profile or byte value for the PHP indexer (e.g. conservative, 256M). Default: from config.')]
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
    $entityTypes = $this->entityTypes($options['entity-type'] ?? '');
    $bundle = $options['bundle'] ?: '';
    $siteName = $config->get('site_name') ?: ($this->configFactory->get('system.site')->get('name') ?? '');
    $language = $config->get('ai_languages')[0] ?? 'en';

    $budget = MemoryBudgetConfig::fromCliAndConfig(
      (isset($options['memory-budget']) && $options['memory-budget'] !== NULL)
        ? (string) $options['memory-budget']
        : NULL,
      (isset($options['chunk-size']) && $options['chunk-size'] !== NULL)
        ? (string) $options['chunk-size']
        : NULL,
      fn() => [
        'profile'    => $config->get('memory_budget.profile') ?? 'conservative',
        'chunk_size' => $config->get('memory_budget.chunk_size'),
      ],
    );

    $resolvedOutputDir = $this->resolvePath(
      $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind'
    );
    $resolvedStateDir = $this->resolveBuildDir(
      $config->get('pagefind.build_dir') ?? 'public://scolta-build'
    );

    if (!is_dir($resolvedStateDir) && !$this->fileSystem->mkdir($resolvedStateDir, 0755, TRUE)) {
      $this->logger()->error('Failed to create state directory: {dir}', ['dir' => $resolvedStateDir]);
      return;
    }
    if (!is_dir($resolvedOutputDir) && !$this->fileSystem->mkdir($resolvedOutputDir, 0755, TRUE)) {
      $this->logger()->error('Failed to create output directory: {dir}', ['dir' => $resolvedOutputDir]);
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
    $orchestrator = new IndexBuildOrchestrator($resolvedStateDir, $resolvedOutputDir, NULL, $language);

    // Where a resumed build restarts its walk, per entity type. The ledger
    // knows exactly which pages this build has already committed, so the
    // cursors are derived from real content ids rather than from
    // pages_processed, which counts pages against a cursor that walks
    // entities. A type with no cursor was not reached before the interruption
    // and is walked from its first row.
    $resumeCursors = $resume ? ScoltaContentGatherer::resumeCursors($orchestrator->pageTableLedger()->seenIdsThisBuild()) : [];
    foreach ($resumeCursors as $type => $id) {
      $this->logger()->notice('Resuming the {type} walk at entity {id}.', ['type' => $type, 'id' => $id]);
    }

    // Expose the timestamp manifest to the gatherer so it can skip full entity
    // loads for unchanged content — the manifest is null-safe, so passing it
    // on resume/restart is harmless.
    //
    // Passed under --force too, which it was not before. --force is a rule
    // about what this build READS: reload every entity, trust nothing cached.
    // Withholding the manifest also stopped the build WRITING to it, and the
    // orchestrator's own pruneAndSave() at the end then found nothing marked
    // seen and emptied it — so a --force build deleted the very state that
    // makes the next build incremental, and that next build was a second cold
    // one. The gatherer gates the skip decision on $force and records
    // regardless, so a --force build now leaves the manifest primed.
    $tsManifest = $orchestrator->getTimestampManifest();

    // Stream content one entity at a time — no full pre-load into RAM. The
    // manifest goes to the exporter as well: it is the exporter that drops
    // bodies too short to index, and it records those so the next build stops
    // re-gathering them.
    $exporter = new ContentExporter($resolvedOutputDir);
    if ($entityIds !== NULL) {
      // Same inclusive resume boundary as the corpus walk: gatherByIds() has
      // no cursor, so the ID list itself is trimmed to it. The boundary entity
      // stays in because only some of its translations may have committed; the
      // orchestrator drops the ones already indexed.
      $resumeFromId = $resumeCursors[$entityTypes[0]] ?? NULL;
      if ($resumeFromId !== NULL) {
        $entityIds = array_values(array_filter($entityIds, fn($id) => (int) $id >= $resumeFromId));
      }
      $source = $this->contentGatherer->gatherByIds($entityTypes[0], $entityIds, $siteName, $tsManifest, $force);
    }
    else {
      $source = (function () use ($entityTypes, $bundle, $siteName, $resumeCursors, $tsManifest, $force) {
        foreach ($entityTypes as $type) {
          yield from $this->contentGatherer->gather($type, $bundle, $siteName, $resumeCursors[$type] ?? NULL, $tsManifest, $force);
        }
      })();
    }
    $items = $exporter->filterItems($source, $tsManifest);

    $report = $orchestrator->build($intent, $items, $this->logger(), $reporter, force: $force);

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
      $this->confirmChainComplete($resolvedOutputDir, 0);
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

      // A process invoked with --resume is a segment of a chain the original
      // process is driving; it reports its own outcome and lets that process
      // decide what happens next. Nesting a chain inside every segment would
      // keep one bootstrapped Drupal alive per segment.
      if ($resume) {
        throw new \RuntimeException(sprintf(
          'Memory limit reached after %d pages. The build is incomplete and the index has not been '
          . 'republished. Re-run `drush scolta:build --resume` to continue, or raise memory_limit.',
          $report->pagesProcessed,
        ));
      }

      $this->runResumeChain($options, $budget->totalBudgetBytes(), $report, $resolvedStateDir, $resolvedOutputDir);
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
   * Resolve an --entity-type value to a type list.
   *
   * @param string $option
   *   Comma-separated entity type IDs, or empty for the configured list.
   *
   * @return string[]
   *   At least one entity type ID.
   */
  private function entityTypes(string $option): array {
    return StringUtils::csvToArray($option) ?: array_keys($this->contentGatherer->entityTypes());
  }

  /**
   * Drive resume segments to completion, in the foreground.
   *
   * This used to be `exec('drush … --resume &')`: the command returned in
   * seconds having indexed nothing, exited 0, and left a detached chain of
   * about twenty processes to decide the real outcome with nobody reading the
   * result. Whatever the chain produced — including an index missing hundreds
   * of pages — the operator and any deploy pipeline had already been told the
   * build succeeded. The process that was asked to build the index now owns
   * whether it exists.
   *
   * @param array $options
   *   The original command options.
   * @param int $budgetBytes
   *   Memory budget to pass to each segment.
   * @param \Tag1\Scolta\Index\StatusReport $firstReport
   *   The report from the segment that ran in this process.
   * @param string $stateDir
   *   Resolved build state directory.
   * @param string $outputDir
   *   Resolved index output directory.
   *
   * @throws \RuntimeException
   *   When the chain stalls, exceeds its segment budget, or fails.
   */
  private function runResumeChain(array $options, int $budgetBytes, $firstReport, string $stateDir, string $outputDir): void {
    $drushBin = $this->findDrushBin();
    if ($drushBin === NULL) {
      throw new \RuntimeException(sprintf(
        'Memory limit reached after %d pages and drush could not be located to continue the build. '
        . 'Run `drush scolta:build --resume` until it completes, or raise memory_limit.',
        $firstReport->pagesProcessed,
      ));
    }

    $cmd = escapeshellarg($drushBin) . ' scolta:build --resume';
    if (!empty($options['entity-type'])) {
      $cmd .= ' --entity-type=' . escapeshellarg((string) $options['entity-type']);
    }
    if (!empty($options['bundle'])) {
      $cmd .= ' --bundle=' . escapeshellarg($options['bundle']);
    }
    if (!empty($options['entity-ids'])) {
      $cmd .= ' --entity-ids=' . escapeshellarg((string) $options['entity-ids']);
    }
    if (isset($options['chunk-size']) && $options['chunk-size'] !== NULL) {
      $cmd .= ' --chunk-size=' . escapeshellarg((string) $options['chunk-size']);
    }
    // --force must survive segmentation: an unforced segment serves any
    // entity whose changed timestamp matches the manifest from cached
    // references, and the manifest still holds the previous build's entries
    // for the whole corpus because pruneAndSave() only runs at end-of-build,
    // which the aborting parent never reached. Without this, a forced build
    // big enough to segment silently degrades to incremental for its tail.
    if (!empty($options['force'])) {
      $cmd .= ' --force';
    }
    $cmd .= ' --memory-budget=' . escapeshellarg(round($budgetBytes / 1_048_576) . 'M');

    $pagesBefore = $firstReport->pagesProcessed;
    $segment = 0;
    $policy = new ResumeChainPolicy(ini_get('memory_limit') ?: NULL, self::MAX_RESUME_SEGMENTS);

    while (TRUE) {
      $segment++;
      $this->logger()->notice(
        'Memory limit reached at {pages} pages. Continuing in a fresh process (segment {n})...',
        ['pages' => $pagesBefore, 'n' => $segment],
      );

      $this->clearSegmentOutcome($stateDir);
      $exitCode = $this->runForeground($cmd);
      if ($exitCode === 0) {
        $this->confirmChainComplete($outputDir, $segment);
        return;
      }

      // Every failure exits non-zero, so exit status alone cannot say whether
      // the segment yielded on memory pressure and wants another one or found
      // the build broken and wants the chain to stop. The segment records
      // which it was; ResumeChainPolicy turns that record into the decision
      // and ends the chain at MAX_RESUME_SEGMENTS.
      $pagesNow = $this->pagesCommitted($stateDir);
      $reason = $policy->failureReason($this->segmentOutcome($stateDir), $pagesNow, $pagesBefore, $segment);
      if ($reason !== NULL) {
        throw new \RuntimeException($reason);
      }
      $pagesBefore = $pagesNow;
    }
  }

  /**
   * Run a command in the foreground, streaming its output, and return its code.
   */
  private function runForeground(string $cmd): int {
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions,Drupal.Commenting.PostStatementComment,Drupal.Commenting.InlineComment,Drupal.Files.LineLength -- nosemgrep trails the call because semgrep reads it only there. proc_open required to stream a child build's output while waiting for it. Arguments are escapeshellarg-quoted.
    $handle = proc_open($cmd . ' 2>&1', [STDIN, ['pipe', 'w'], ['pipe', 'w']], $pipes); // nosemgrep: php.lang.security.exec-use.exec-use
    if ($handle === FALSE) {
      throw new \RuntimeException('Failed to start the resume segment: ' . $cmd);
    }

    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- feof/fgets/fclose/proc_close required for subprocess pipe operations.
    while (!feof($pipes[1])) {
      $line = fgets($pipes[1]);
      if ($line !== FALSE && trim($line) !== '') {
        $this->logger()->notice(rtrim($line));
      }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($handle);
  }

  /**
   * How the last segment reported it ended, or NULL if it never reported.
   *
   * @return array|null
   *   The outcome BuildState recorded, or NULL when none is readable.
   */
  private function segmentOutcome(string $stateDir): ?array {
    try {
      return (new BuildState($stateDir))->readOutcome();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Drop any outcome on disk so the next segment's silence reads as silence.
   */
  private function clearSegmentOutcome(string $stateDir): void {
    try {
      (new BuildState($stateDir))->clearOutcome();
    }
    catch (\Throwable) {
      // A state dir this cannot open is one the segment will fail on anyway.
    }
  }

  /**
   * Pages the shared build manifest records as committed so far.
   */
  private function pagesCommitted(string $stateDir): int {
    try {
      return (new BuildState($stateDir))->getPagesProcessed();
    }
    catch (\Throwable) {
      return 0;
    }
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
  private function confirmChainComplete(string $outputDir, int $segments): void {
    $this->assertIndexUsable($outputDir);

    $fragments = glob($outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
    $generation = $this->state->get('scolta.generation', 0);
    $this->state->set('scolta.generation', $generation + 1);
    $this->logger()->success('Index built: {pages} pages on disk{via}.', [
      'pages' => count($fragments),
      'via' => $segments > 0 ? " (completed across {$segments} resume segments)" : ' (finalized in a fresh process)',
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
    $drushBin = $this->findDrushBin();
    if ($drushBin === NULL) {
      $this->logger()->error('Cannot auto-finalize: drush executable not found. Run manually: drush scolta:finalize');
      return;
    }

    $budgetMb = round($budgetBytes / 1_048_576) . 'M';
    $cmd = escapeshellarg($drushBin)
      . ' scolta:finalize'
      . ' --state-dir=' . escapeshellarg($stateDir)
      . ' --output-dir=' . escapeshellarg($outputDir)
      . ' --memory-budget=' . escapeshellarg($budgetMb)
      . ' 2>&1';

    $this->logger()->notice('Running: {cmd}', ['cmd' => $cmd]);

    // phpcs:ignore Drupal.Functions.DiscouragedFunctions,Drupal.Commenting.PostStatementComment,Drupal.Commenting.InlineComment,Drupal.Files.LineLength -- nosemgrep trails the call because semgrep reads it only there. proc_open required for pagefind subprocess execution with real-time output streaming. Arguments are escapeshellarg-quoted.
    $handle = proc_open($cmd, [STDIN, ['pipe', 'w'], ['pipe', 'w']], $pipes); // nosemgrep: php.lang.security.exec-use.exec-use
    if ($handle === FALSE) {
      $this->logger()->error('proc_open() failed. Run manually: drush scolta:finalize');
      return;
    }

    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- feof/fgets/fclose/proc_close required for subprocess pipe operations.
    while (!feof($pipes[1])) {
      $line = fgets($pipes[1]);
      if ($line !== FALSE && trim($line) !== '') {
        $this->logger()->notice(rtrim($line));
      }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($handle);

    if ($exitCode !== 0) {
      throw new \RuntimeException(sprintf(
        'scolta:finalize exited with code %d, so the merge did not complete and no index was published. '
        . 'Re-run `drush scolta:finalize` once memory pressure has eased.',
        $exitCode,
      ));
    }
  }

  /**
   * Locate the drush binary.
   */
  private function findDrushBin(): ?string {
    // Vendor bin is the most reliable location in a Composer project.
    $root = defined('DRUPAL_ROOT') ? dirname(DRUPAL_ROOT) : getcwd();
    $vendorBin = $root . '/vendor/bin/drush';
    if (is_executable($vendorBin)) {
      return $vendorBin;
    }
    // Fall back to PATH.
    $which = trim((string) shell_exec('which drush 2>/dev/null'));
    return ($which !== '' && is_executable($which)) ? $which : NULL;
  }

  /**
   * Resolve a stream-wrapper URI or plain path to an absolute filesystem path.
   */
  private function resolvePath(string $uri): string {
    if (!str_contains($uri, '://')) {
      return $uri;
    }
    try {
      $wrapper = $this->streamWrapperManager->getViaUri($uri);
      return ($wrapper && ($path = $wrapper->realpath())) ? $path : $uri;
    }
    catch (\Throwable) {
      return $uri;
    }
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
   * Merge committed index chunks into the final Pagefind-compatible index.
   *
   * Use this after `scolta:build` exits with "merge deferred" on large
   * corpora where the PHP heap is too fragmented to merge in-process.
   * The chunks must already be committed to the build state directory.
   */
  #[CLI\Command(name: 'scolta:finalize', aliases: ['sf'])]
  #[CLI\Option(name: 'state-dir', description: 'Build state directory (default: from config)')]
  #[CLI\Option(name: 'output-dir', description: 'Output directory for the final index (default: from config)')]
  #[CLI\Option(name: 'memory-budget', description: 'Memory profile or byte value (default: from config)')]
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

    $budget = MemoryBudgetConfig::fromCliAndConfig(
      (isset($options['memory-budget']) && $options['memory-budget'] !== NULL)
        ? (string) $options['memory-budget']
        : NULL,
      NULL,
      fn() => [
        'profile'    => $config->get('memory_budget.profile') ?? 'conservative',
        'chunk_size' => $config->get('memory_budget.chunk_size'),
      ],
    );

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
   * Checks PHP version, runtime requirements, and AI key.
   */
  #[CLI\Command(name: 'scolta:check-setup', aliases: ['scs'])]
  public function checkSetup(): void {
    $results = SetupCheck::run(
      configuredBinaryPath: NULL,
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
   * Show Scolta status: build directory, index, AI provider, cache.
   *
   * Emits YAML on stdout so the section groupings survive machine
   * consumption — logger lines flattened the structure and went to stderr.
   */
  #[CLI\Command(name: 'scolta:status', aliases: ['sst'])]
  public function status(): void {
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

}
