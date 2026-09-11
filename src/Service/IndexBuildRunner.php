<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Psr\Log\LoggerInterface;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\ProgressReporterInterface;
use Tag1\Scolta\Index\ResumeChainPolicy;
use Tag1\Scolta\Index\ResumeChainRunner;
use Tag1\Scolta\Index\StatusReport;

/**
 * The build path `drush scolta:build` and the rebuild queue worker share.
 *
 * Both drivers resolve the same config into the same directories and memory
 * budget, stream the same gatherer through the same orchestrator, and carry a
 * memory-yielded build to its end by spawning `drush scolta:build --resume`
 * child processes. Each used to hold its own copy of all of that, so a fix
 * to one lagged in the other. What stays with the driver is what differs:
 * the queue worker's lock, debounce and marker bookkeeping, and the command's
 * scoping options and operator-facing messages.
 *
 * @since 1.4.1
 * @stability experimental
 */
class IndexBuildRunner {

  /**
   * The Drupal lock the queue worker holds around a build.
   */
  public const LOCK_NAME = 'scolta_build';

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly StreamWrapperManagerInterface $streamWrapperManager,
    protected readonly ScoltaContentGatherer $contentGatherer,
  ) {}

  /**
   * The entity types a build walks: a comma-separated override, else config.
   *
   * @return string[]
   *   Entity type IDs, in walk order.
   */
  public function entityTypes(string $override = ''): array {
    $types = array_values(array_filter(array_map('trim', explode(',', $override))));
    return $types ?: array_keys($this->contentGatherer->entityTypes());
  }

  /**
   * The memory budget: CLI values first, scolta.settings for whatever is unset.
   */
  public function memoryBudget(?string $budgetOption = NULL, ?string $chunkSizeOption = NULL): MemoryBudget {
    $config = $this->configFactory->get('scolta.settings');
    return MemoryBudgetConfig::fromCliAndConfig($budgetOption, $chunkSizeOption, fn() => [
      'profile' => $config->get('memory_budget.profile') ?? 'conservative',
      'chunk_size' => $config->get('memory_budget.chunk_size'),
    ]);
  }

  /**
   * The site name recorded on each indexed page.
   */
  public function siteName(): string {
    return $this->configFactory->get('scolta.settings')->get('site_name')
      ?: ($this->configFactory->get('system.site')->get('name') ?? '');
  }

  /**
   * The stemming language.
   */
  public function language(): string {
    return $this->configFactory->get('scolta.settings')->get('ai_languages')[0] ?? 'en';
  }

  /**
   * The resolved index output directory, created if missing.
   *
   * @throws \RuntimeException
   *   When the directory cannot be created.
   */
  public function outputDir(): string {
    $dir = $this->resolvePath($this->configFactory->get('scolta.settings')->get('pagefind.output_dir') ?? 'public://scolta-pagefind');
    $this->ensureDir($dir, 'output');
    return $dir;
  }

  /**
   * The resolved build state directory, created and format-marked.
   *
   * Falls back to public://scolta-build when the configured directory uses
   * private:// and no private file system is configured. The default matches
   * config/install/scolta.settings.yml; the command and the worker once
   * defaulted differently and rebuilt from scratch in turn, each blind to the
   * other's manifest.
   *
   * @throws \RuntimeException
   *   When the directory cannot be created.
   */
  public function stateDir(?LoggerInterface $logger = NULL): string {
    $uri = $this->configFactory->get('scolta.settings')->get('pagefind.build_dir') ?? 'public://scolta-build';
    $dir = $this->resolvePath($uri);
    if ($dir === $uri && str_starts_with($uri, 'private://')) {
      $logger?->notice('Private file system not configured; using public://scolta-build for index storage.');
      $publicBase = $this->resolvePath('public://');
      if ($publicBase !== 'public://') {
        $dir = $publicBase . '/scolta-build';
      }
    }
    $this->ensureDir($dir, 'state');
    scolta_mark_state_format($dir);
    return $dir;
  }

  /**
   * Resolve a stream-wrapper URI or plain path to an absolute filesystem path.
   */
  public function resolvePath(string $uri): string {
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
   * Stream the corpus through one build segment.
   *
   * Content goes one entity at a time through the shared gatherer —
   * translations, text-format rendering, field mappings and the alter hook
   * all apply — with no eager load of the corpus. One timestamp manifest
   * serves both stages: the gatherer reads it to skip unchanged entities and
   * records what it loads, and the exporter records the bodies it drops for
   * being too short to index.
   *
   * @param \Tag1\Scolta\Index\IndexBuildOrchestrator $orchestrator
   *   The orchestrator for the state and output directories.
   * @param string $outputDir
   *   The resolved index output directory the exporter writes under.
   * @param \Tag1\Scolta\Index\BuildIntent $intent
   *   Fresh, restart or resume.
   * @param string[] $entityTypes
   *   The entity types to walk, in order.
   * @param array<string, int> $cursors
   *   Entity type ID => the entity ID to resume that type's walk at.
   * @param \Psr\Log\LoggerInterface $logger
   *   Where the orchestrator reports.
   * @param \Tag1\Scolta\Index\ProgressReporterInterface $reporter
   *   Progress sink; the worker's renews its lock at every chunk boundary.
   * @param string $bundle
   *   Scope the walk to one bundle, or '' for all.
   * @param string[]|null $entityIds
   *   Scope the walk to these IDs of $entityTypes[0], or NULL for no scoping.
   * @param bool $force
   *   Reload every entity, trusting nothing cached.
   */
  public function runSegment(IndexBuildOrchestrator $orchestrator, string $outputDir, BuildIntent $intent, array $entityTypes, array $cursors, LoggerInterface $logger, ProgressReporterInterface $reporter, string $bundle = '', ?array $entityIds = NULL, bool $force = FALSE): StatusReport {
    $siteName = $this->siteName();
    $tsManifest = $orchestrator->getTimestampManifest();
    $exporter = new ContentExporter($outputDir);

    if ($entityIds !== NULL) {
      // Same inclusive resume boundary as the corpus walk: gatherByIds() has
      // no cursor, so the ID list itself is trimmed to it. The boundary entity
      // stays in because only some of its translations may have committed;
      // the orchestrator drops the ones already indexed.
      $resumeFromId = $cursors[$entityTypes[0]] ?? NULL;
      if ($resumeFromId !== NULL) {
        $entityIds = array_values(array_filter($entityIds, fn($id) => (int) $id >= $resumeFromId));
      }
      $source = $this->contentGatherer->gatherByIds($entityTypes[0], $entityIds, $siteName, $tsManifest, $force);
    }
    else {
      $source = (function () use ($entityTypes, $bundle, $siteName, $cursors, $tsManifest, $force) {
        foreach ($entityTypes as $type) {
          yield from $this->contentGatherer->gather($type, $bundle, $siteName, $cursors[$type] ?? NULL, $tsManifest, $force);
        }
      })();
    }

    return $orchestrator->build($intent, $exporter->filterItems($source, $tsManifest), $logger, $reporter, force: $force);
  }

  /**
   * Where a resumed build restarts its walk, per entity type.
   *
   * The ledger knows exactly which pages this build has committed, so the
   * cursors come from real content IDs rather than from pages_processed,
   * which counts pages against a walk over entities. A type with no cursor
   * was not reached before the interruption and is walked from its first row.
   *
   * @return array<string, int>
   *   Entity type ID => entity ID.
   */
  public function resumeCursors(IndexBuildOrchestrator $orchestrator): array {
    return ScoltaContentGatherer::resumeCursors($orchestrator->pageTableLedger()->seenIdsThisBuild());
  }

  /**
   * The policy deciding whether a failed segment is resumed or ends the build.
   */
  public function policy(): ResumeChainPolicy {
    return new ResumeChainPolicy(ini_get('memory_limit') ?: NULL);
  }

  /**
   * Carry a memory-yielded build to its end, one fresh process per segment.
   *
   * A second segment in the heap the first one fragmented is judged a stall,
   * so each segment is a child `drush scolta:build --indexer=php --resume`.
   * The loop itself is scolta-php's ResumeChainRunner; this supplies the
   * command line and the process runner.
   *
   * @param \Tag1\Scolta\Index\BuildState $state
   *   The state directory the build runs against.
   * @param \Tag1\Scolta\Index\StatusReport $yielded
   *   The memory-aborted report of the segment that ran in this process.
   * @param \Tag1\Scolta\Index\MemoryBudget $budget
   *   Passed to each segment as --memory-budget.
   * @param \Psr\Log\LoggerInterface $logger
   *   Receives the chain's notices and each child's output.
   * @param callable(string, array<string, string>): int $runChild
   *   Runs one child command with the given environment variables set and
   *   returns its exit code; see runForeground(). A seam for tests.
   * @param string $extraArgs
   *   Shell-escaped options every segment must repeat (scope, --force …).
   *
   * @return \Tag1\Scolta\Index\StatusReport
   *   Success once a segment exits 0; otherwise the policy's reason to stop.
   *
   * @throws \RuntimeException
   *   When drush cannot be located, so no segment can be spawned.
   */
  public function resumeChain(BuildState $state, StatusReport $yielded, MemoryBudget $budget, LoggerInterface $logger, callable $runChild, string $extraArgs = ''): StatusReport {
    $drushBin = $this->findDrushBin();
    if ($drushBin === NULL) {
      throw new \RuntimeException(sprintf(
        'Memory limit reached after %d pages and drush could not be located to continue the build. '
        . 'Run `drush scolta:build --resume` until it completes, or raise memory_limit.',
        $yielded->pagesProcessed,
      ));
    }
    $cmd = escapeshellarg($drushBin) . ' scolta:build --indexer=php --resume' . $extraArgs
      . ' --memory-budget=' . escapeshellarg(round($budget->totalBudgetBytes() / 1_048_576) . 'M');

    $runner = new ResumeChainRunner($state, $this->policy(), fn(array $env): int => $runChild($cmd, $env), $logger);
    return $runner->run($yielded);
  }

  /**
   * Run a command in the foreground, streaming its output, and return its code.
   *
   * @param string $cmd
   *   The shell-escaped command line.
   * @param array<string, string> $env
   *   Environment variables to set for the child.
   * @param \Psr\Log\LoggerInterface $logger
   *   Receives each non-empty output line at notice level.
   * @param callable|null $keepAlive
   *   Called at least every 30 seconds while the child runs, whether or not
   *   it printed anything; the worker renews its lock here.
   */
  public function runForeground(string $cmd, array $env, LoggerInterface $logger, ?callable $keepAlive = NULL): int {
    foreach (array_reverse($env, TRUE) as $name => $value) {
      $cmd = $name . '=' . escapeshellarg($value) . ' ' . $cmd;
    }
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions,Drupal.Commenting.PostStatementComment,Drupal.Commenting.InlineComment,Drupal.Files.LineLength -- nosemgrep trails the call because semgrep reads it only there. proc_open required to stream a child build's output while waiting for it. Arguments are escapeshellarg-quoted.
    $handle = proc_open($cmd . ' 2>&1', [STDIN, ['pipe', 'w'], ['pipe', 'w']], $pipes); // nosemgrep: php.lang.security.exec-use.exec-use
    if ($handle === FALSE) {
      throw new \RuntimeException('Failed to start the resume segment: ' . $cmd);
    }

    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- feof/fgets/fclose/proc_close required for subprocess pipe operations.
    while (!feof($pipes[1])) {
      $read = [$pipes[1]];
      $write = $except = [];
      if (!stream_select($read, $write, $except, 30)) {
        $keepAlive && $keepAlive();
        continue;
      }
      $line = fgets($pipes[1]);
      if ($line !== FALSE && trim($line) !== '') {
        $logger->notice(rtrim($line));
      }
      $keepAlive && $keepAlive();
    }
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($handle);
  }

  /**
   * Locate the drush binary.
   */
  public function findDrushBin(): ?string {
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
   * Create a directory the build needs, or refuse with a message naming it.
   *
   * @throws \RuntimeException
   */
  protected function ensureDir(string $dir, string $role): void {
    if (!is_dir($dir) && !$this->fileSystem->mkdir($dir, 0755, TRUE)) {
      throw new \RuntimeException(sprintf('Failed to create %s directory: %s', $role, $dir));
    }
  }

}
