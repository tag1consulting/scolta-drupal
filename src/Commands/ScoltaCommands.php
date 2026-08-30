<?php

declare(strict_types=1);

namespace Drupal\scolta\Commands;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Progress\DrushProgressReporter;
use Drupal\scolta\Service\IndexLocator;
use Drupal\scolta\Service\ScoltaAiService;
use Drupal\scolta\Service\ScoltaContentGatherer;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\RetiredIndexTrash;
use Tag1\Scolta\Prompt\DefaultPrompts;
use Tag1\Scolta\SetupCheck;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * Drush commands for Scolta.
 *
 * Scolta:export  -- Export CMS content as HTML files.
 * scolta:build   -- Run export, pagefind CLI, deploy.
 * scolta:clear-cache -- Clear expansion/summary caches.
 * scolta:cleanup -- Delete retired index (.scolta-trash-*) directories.
 * scolta:download-pagefind -- Download the Pagefind binary.
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
  #[CLI\Argument(name: 'entity_type', description: 'Entity type to export (default: node)')]
  #[CLI\Option(name: 'bundle', description: 'Bundle/content type to export (default: all)')]
  #[CLI\Option(name: 'output-dir', description: 'Output directory for HTML files')]
  #[CLI\Usage(name: 'scolta:export node --bundle=article', description: 'Export all published articles')]
  #[CLI\Usage(name: 'scolta:export node --bundle=page --output-dir=/var/www/html/pagefind-site', description: 'Export pages to specific directory')]
  public function export(
    string $entity_type = 'node',
    array $options = ['bundle' => '', 'output-dir' => ''],
  ): void {
    $config = $this->configFactory->get('scolta.settings');
    $outputDir = $options['output-dir'] ?: '/var/www/html/pagefind-site';
    $bundle = $options['bundle'] ?: '';
    $siteName = $config->get('site_name') ?: ($this->configFactory->get('system.site')->get('name') ?? '');

    $exporter = new ContentExporter($outputDir);
    $exporter->prepareOutputDir();

    $items = $this->contentGatherer->gather($entity_type, $bundle, $siteName);

    if (empty($items)) {
      $this->logger()->warning('No published entities found.');
      return;
    }

    foreach ($items as $item) {
      $exporter->export($item);
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
   */
  #[CLI\Command(name: 'scolta:build', aliases: ['sb'])]
  #[CLI\Option(name: 'entity-type', description: 'Entity type to export')]
  #[CLI\Option(name: 'bundle', description: 'Bundle to export')]
  #[CLI\Option(name: 'entity-ids', description: 'Comma-separated entity IDs to index. Scopes the build to exactly these entities — the published index contains only them, like --bundle scopes it to a bundle. IDs that cannot be loaded are logged and skipped. PHP indexer only; --bundle is ignored.')]
  #[CLI\Option(name: 'output-dir', description: 'Export directory')]
  #[CLI\Option(name: 'docroot', description: 'Docroot path')]
  #[CLI\Option(name: 'skip-pagefind', description: 'Export content only, skip Pagefind build')]
  #[CLI\Option(name: 'indexer', description: 'Indexer mode: php, binary, or auto (default: from config)')]
  #[CLI\Option(name: 'force', description: 'Force rebuild even if content has not changed')]
  #[CLI\Option(name: 'memory-budget', description: 'Memory profile or byte value for the PHP indexer (e.g. conservative, 256M). Default: from config.')]
  #[CLI\Option(name: 'chunk-size', description: 'Pages per chunk during a PHP index build. Overrides the profile default and config setting.')]
  #[CLI\Option(name: 'resume', description: 'Resume a previously interrupted PHP index build')]
  #[CLI\Option(name: 'restart', description: 'Discard interrupted state and restart the PHP index build')]
  public function build(
    array $options = [
      'entity-type' => 'node',
      'bundle' => '',
      'entity-ids' => '',
      'output-dir' => '/var/www/html/pagefind-site',
      'docroot' => 'docroot',
      'skip-pagefind' => FALSE,
      'indexer' => '',
      'force' => FALSE,
      'memory-budget' => NULL,
      'chunk-size' => NULL,
      'resume' => FALSE,
      'restart' => FALSE,
    ],
  ): void {
    $config = $this->configFactory->get('scolta.settings');

    // Resolve indexer mode: CLI option overrides config.
    $indexerMode = $options['indexer'] ?: ($config->get('indexer') ?: 'auto');

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
    $this->export($options['entity-type'], [
      'bundle' => $options['bundle'],
      'output-dir' => $options['output-dir'],
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
    $this->runPagefind($options['output-dir'], $resolvedOutputDir . '/pagefind');
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
    $entityType = $options['entity-type'] ?: 'node';
    $bundle     = $options['bundle'] ?: '';
    $siteName   = $config->get('site_name') ?: ($this->configFactory->get('system.site')->get('name') ?? '');
    $language   = $config->get('ai_languages')[0] ?? 'en';

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

    // NULL means "no scoping" — the build walks the whole corpus. An explicit
    // ID list, even one that resolved to nothing, must not fall through to a
    // full walk.
    $entityIds = ($options['entity-ids'] ?? '') !== ''
      ? $this->resolveEntityIds($entityType, (string) $options['entity-ids'])
      : NULL;

    $totalCount = $entityIds !== NULL
      ? count($entityIds)
      : $this->contentGatherer->gatherCount($entityType, $bundle);
    if ($totalCount === 0) {
      $this->logger()->warning('No content found to index.');
      return;
    }
    $this->logger()->notice('Gathering content (PHP indexer): {count} entities.', ['count' => $totalCount]);

    $resume = (bool) ($options['resume'] ?? FALSE);
    $restart = (bool) ($options['restart'] ?? FALSE);

    $intent = BuildIntentFactory::fromFlags($resume, $restart, $totalCount, $budget);

    $reporter = new DrushProgressReporter($this->output());
    $orchestrator = new IndexBuildOrchestrator($resolvedStateDir, $resolvedOutputDir, NULL, $language);

    // Where a resumed build restarts its walk. The ledger knows exactly which
    // pages this build has already committed, so the cursor is derived from
    // real content ids rather than from pages_processed, which counts pages
    // against a cursor that walks entities.
    $resumeFromId = $resume ? $this->resumeCursor($orchestrator) : NULL;
    if ($resumeFromId !== NULL) {
      $this->logger()->notice('Resuming the content walk at entity {id}.', ['id' => $resumeFromId]);
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
      if ($resumeFromId !== NULL) {
        $entityIds = array_values(array_filter($entityIds, fn($id) => (int) $id >= $resumeFromId));
      }
      $source = $this->contentGatherer->gatherByIds($entityType, $entityIds, $siteName, $tsManifest, $force);
    }
    else {
      $source = $this->contentGatherer->gather($entityType, $bundle, $siteName, $resumeFromId, $tsManifest, $force);
    }
    $items = $exporter->filterItems($source, $tsManifest);

    $report = $orchestrator->build($intent, $items, $this->logger(), $reporter, force: $force);

    if ($report->success) {
      $this->reportBuildSuccess($report, $resolvedOutputDir);
      return;
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
   * The entity ID a resumed build should restart its content walk at.
   *
   * The ledger holds one row per *page* this build committed, keyed by the
   * content item ID the gatherer produced ('42' for a single-language node,
   * '42-es' for a translation). The walk is over *entities*, so the cursor is
   * the highest entity those rows mention. It is used inclusively, because
   * that entity may have had only some of its translations committed before
   * the memory limit hit; the orchestrator drops the ones already indexed.
   *
   * Returns NULL when the ledger is empty or holds an ID this cannot read as
   * an entity ID, in which case the build re-reads from the start — slower,
   * and never wrong.
   */
  private function resumeCursor(IndexBuildOrchestrator $orchestrator): ?int {
    $highest = NULL;

    foreach ($orchestrator->pageTableLedger()->seenIdsThisBuild() as $itemId) {
      // '42-es' is entity 42; anything that does not lead with digits is not
      // an ID this walk can seek to.
      $entityId = strtok($itemId, '-');
      if ($entityId === FALSE || !ctype_digit($entityId)) {
        return NULL;
      }
      $entityId = (int) $entityId;
      if ($highest === NULL || $entityId > $highest) {
        $highest = $entityId;
      }
    }

    return $highest;
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

    $cmd = escapeshellarg($drushBin) . ' scolta:build --indexer=php --resume';
    $entityType = $options['entity-type'] ?? 'node';
    if ($entityType !== 'node') {
      $cmd .= ' --entity-type=' . escapeshellarg($entityType);
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

    while ($segment < self::MAX_RESUME_SEGMENTS) {
      $segment++;
      $this->logger()->notice(
        'Memory limit reached at {pages} pages. Continuing in a fresh process (segment {n})...',
        ['pages' => $pagesBefore, 'n' => $segment],
      );

      $exitCode = $this->runForeground($cmd);
      if ($exitCode === 0) {
        $this->confirmChainComplete($outputDir, $segment);
        return;
      }

      // A non-zero segment either failed outright or hit the limit again. The
      // difference is whether it committed anything, and the manifest is the
      // only witness both processes share.
      $pagesNow = $this->pagesCommitted($stateDir);
      if ($pagesNow <= $pagesBefore) {
        throw new \RuntimeException(sprintf(
          'The build stalled at %d pages: segment %d committed nothing before hitting the memory limit again. '
          . 'The index has not been republished. Raise PHP memory_limit (currently %s) or lower --chunk-size, '
          . 'then re-run with --restart.',
          $pagesNow,
          $segment,
          ini_get('memory_limit') ?: 'unknown',
        ));
      }
      $pagesBefore = $pagesNow;
    }

    throw new \RuntimeException(sprintf(
      'The build did not complete within %d resume segments (%d pages committed). '
      . 'The index has not been republished. Raise PHP memory_limit (currently %s) so fewer segments are needed, '
      . 'then re-run with --restart.',
      self::MAX_RESUME_SEGMENTS,
      $pagesBefore,
      ini_get('memory_limit') ?: 'unknown',
    ));
  }

  /**
   * Run a command in the foreground, streaming its output, and return its code.
   */
  private function runForeground(string $cmd): int {
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- proc_open required to stream a child build's output while waiting for it.
    $handle = proc_open($cmd . ' 2>&1', [STDIN, ['pipe', 'w'], ['pipe', 'w']], $pipes);
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

    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- proc_open required for pagefind subprocess execution with real-time output streaming.
    $handle = proc_open($cmd, [STDIN, ['pipe', 'w'], ['pipe', 'w']], $pipes);
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
      'source-dir' => '/var/www/html/pagefind-site',
      'output-dir' => '',
    ],
  ): void {
    $sourceDir = $options['source-dir'];
    $outputDir = $options['output-dir'] ?: dirname($sourceDir) . '/pagefind';
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
    exec($cmd, $output, $result);
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
    $outputDir = rtrim($this->resolvePath(
      $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind'
    ), '/');
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
    $oldDir = $outputDir . '/.scolta-old';
    if (file_exists($oldDir)) {
      $trash->retire($oldDir);
    }

    $dirs = $trash->trashDirs();
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
   */
  #[CLI\Command(name: 'scolta:status', aliases: ['sst'])]
  public function status(): void {
    $config = $this->configFactory->get('scolta.settings');

    // Search API index status.
    $this->logger()->notice('--- Search API ---');
    try {
      $indexes = $this->entityTypeManager
        ->getStorage('search_api_index')
        ->loadMultiple();
      $found = FALSE;
      foreach ($indexes as $index) {
        if ($index->getServerId() && str_contains($index->getServerId(), 'scolta')) {
          $tracker = $index->getTrackerInstance();
          $indexed = $tracker->getIndexedItemsCount();
          $total = $tracker->getTotalItemsCount();
          $statusLabel = $index->status() ? 'enabled' : 'disabled';
          $this->logger()->notice("  Index: {$index->label()} ({$statusLabel})");
          $this->logger()->notice("  Indexed: {$indexed}/{$total}");
          $found = TRUE;
        }
      }
      if (!$found) {
        $this->logger()->warning('  No Scolta index configured.');
      }
    }
    catch (\Exception $e) {
      $this->logger()->warning('  Could not query Search API: ' . $e->getMessage());
    }

    // Indexer selection and active state.
    $this->logger()->notice('--- Indexer ---');
    $indexerSetting = $config->get('indexer') ?: 'auto';
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
      $activeIndexer = $binaryStatus['available'] ? 'binary' : 'binary (not found — check path)';
    }
    else {
      $activeIndexer = 'php';
    }
    $this->logger()->notice("  Active indexer: {$activeIndexer}");
    if ($indexerSetting === 'binary') {
      if ($binaryStatus['available']) {
        $this->logger()->notice("  Binary:         {$binaryStatus['message']}");
      }
      else {
        $this->logger()->warning('  Binary:         NOT AVAILABLE');
        $this->logger()->notice("  {$binaryStatus['message']}");
        $this->logger()->notice('  To upgrade: npm install -g pagefind  OR  drush scolta:download-pagefind');
      }
    }

    // Build directory.
    $this->logger()->notice('--- Build Directory ---');
    $buildDirConfig = $config->get('pagefind.build_dir') ?? 'public://scolta-build';
    $resolvedBuildDir = $this->resolveBuildDir($buildDirConfig);
    if ($resolvedBuildDir !== $buildDirConfig) {
      $this->logger()->notice("  Configured: {$buildDirConfig}");
      $this->logger()->notice("  Resolved:   {$resolvedBuildDir}");
    }
    else {
      $this->logger()->notice("  Path: {$resolvedBuildDir}");
    }
    if (is_dir($resolvedBuildDir)) {
      $this->logger()->notice('  Status:     exists');
    }
    else {
      $this->logger()->notice('  Status:     not created yet (created on first build)');
    }

    // Pagefind index.
    $this->logger()->notice('--- Pagefind Index ---');
    $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    if (str_contains($outputDir, '://')) {
      try {
        $resolvedDir = $this->streamWrapperManager
          ->getViaUri($outputDir)->realpath() ?: $outputDir;
      }
      catch (\Exception $e) {
        $resolvedDir = $outputDir;
      }
    }
    else {
      $resolvedDir = $outputDir;
    }
    $location = $this->indexLocator->locate($resolvedDir);
    if ($location !== NULL) {
      $mtime = filemtime($location['indexFile']);
      $this->logger()->notice("  Path:       {$outputDir}");
      // Read the count from pagefind-entry.json rather than counting fragment
      // files: on NFS with a six-figure corpus that glob() is minutes-slow
      // (see PagefindBuilder::getStatus()), and status only needs a number.
      $pageCount = $this->indexLocator->pageCount($location);
      if ($pageCount === NULL) {
        $pageCount = $this->indexLocator->countFragments($location);
      }
      $this->logger()->notice("  Pages:      {$pageCount}");
      $this->logger()->notice("  Last built: " . ($mtime ? date('Y-m-d H:i:s', $mtime) : 'unknown'));
    }
    else {
      $this->logger()->notice("  Path: {$outputDir} (no index built yet)");
    }

    // AI provider. Routing only goes through the Drupal AI module when the
    // admin explicitly selected 'drupal_ai' AND the module is installed —
    // mirror that here instead of reporting on module presence alone.
    $this->logger()->notice('--- AI Provider ---');
    // No coalescing to a provider nobody chose: an empty value means AI is off,
    // and a status command has to report that rather than name Anthropic.
    $provider = $config->get('ai_provider') ?? '';
    if ($provider === '') {
      $this->logger()->notice('  Provider: none selected — AI features are off (search is unaffected)');
    }
    elseif ($provider === 'drupal_ai' && $this->aiService->hasDrupalAiModule()) {
      $this->logger()->notice('  Provider: Drupal AI module');
    }
    elseif ($provider === 'drupal_ai') {
      $this->logger()->notice('  Provider: drupal_ai selected but AI module not installed — falling back to built-in client');
    }
    else {
      $this->logger()->notice("  Provider: {$provider} (built-in)");
    }
    // The source and the description come from the same resolution the client
    // uses, so `status` cannot claim Amazee.ai while an explicit key serves
    // every request (scolta-php#252).
    $resolvedKey = $this->aiService->resolveApiKey();
    $this->logger()->notice("  API key:  {$resolvedKey->source->value}");
    $this->logger()->notice('  ' . $resolvedKey->describe());

    // Generation counter.
    $generation = $this->state->get('scolta.generation', 0);
    $this->logger()->notice("  Cache generation: {$generation}");
  }

  /**
   * Download the Pagefind binary for the current platform.
   *
   * Detects OS and architecture, fetches the latest release from GitHub,
   * and extracts the binary to the specified location.
   */
  #[CLI\Command(name: 'scolta:download-pagefind', aliases: ['sdp'])]
  #[CLI\Option(name: 'version', description: 'Pagefind version to download (default: latest)')]
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
    exec("{$binaryPath} --version 2>&1", $output, $exitCode);
    if ($exitCode === 0) {
      $this->logger()->notice('Verified: ' . implode(' ', $output));
    }
    else {
      $this->logger()->warning('Binary was extracted but --version check failed. You may need to adjust your PATH or permissions.');
    }
  }

}
