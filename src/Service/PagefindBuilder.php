<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Core\File\FileSystemInterface;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Export\ContentExporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Orchestrates the Pagefind CLI to build the static search index.
 *
 * Takes a directory of HTML files (produced by PagefindExporter) and
 * invokes `pagefind --site <dir>` to create the search index bundle
 * (_pagefind/ directory with JS, WASM, and chunked index files).
 */
class PagefindBuilder {

  public function __construct(
    protected readonly LoggerInterface $logger,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly IndexLocator $indexLocator,
  ) {}

  /**
   * Build the Pagefind index from a directory of HTML files.
   *
   * @param string $binary
   *   Path to the pagefind binary ('pagefind', 'npx pagefind', or absolute).
   * @param string $buildDir
   *   Directory containing the exported HTML files.
   * @param string $outputDir
   *   Where the _pagefind/ bundle should be written.
   *
   * @return array{success: bool, output: string, error: ?string,
   *   file_count: ?int, index_size: ?string}
   *   The build result array.
   */
  public function build(string $binary, string $buildDir, string $outputDir): array {
    if (!is_dir($buildDir)) {
      return [
        'success' => FALSE,
        'output' => '',
        'error' => "Build directory does not exist: {$buildDir}",
        'file_count' => NULL,
        'index_size' => NULL,
      ];
    }

    // Count HTML files recursively (nested directory layout).
    $fileCount = ContentExporter::countHtmlFiles($buildDir);

    if ($fileCount === 0) {
      return [
        'success' => FALSE,
        'output' => '',
        'error' => "No HTML files found in {$buildDir}. Run Search API indexing first.",
        'file_count' => 0,
        'index_size' => NULL,
      ];
    }

    // Ensure output directory exists.
    if (!is_dir($outputDir)) {
      if (!$this->fileSystem->mkdir($outputDir, 0755, TRUE)) {
        return [
          'success' => FALSE,
          'output' => '',
          'error' => "Failed to create output directory: {$outputDir}",
          'file_count' => $fileCount,
          'index_size' => NULL,
        ];
      }
    }

    // Build the pagefind command.
    // Handle both "pagefind" (direct binary) and "npx pagefind" (via npm).
    $parts = explode(' ', $binary, 2);

    // Validate binary name — only allow known Pagefind invocation patterns.
    $allowedBinaries = ['pagefind', 'npx', 'node_modules/.bin/pagefind'];
    $baseBinary = basename($parts[0]);
    if (!in_array($baseBinary, $allowedBinaries, TRUE) && !in_array($parts[0], $allowedBinaries, TRUE)) {
      $this->logger->error('Rejected Pagefind binary: @binary', ['@binary' => $binary]);
      return [
        'success' => FALSE,
        'output' => '',
        'error' => 'Invalid Pagefind binary path',
        'file_count' => $fileCount,
        'index_size' => NULL,
      ];
    }

    $command = array_merge(
      $parts,
      [
        '--site', $buildDir,
        '--output-path', $outputDir,
      ]
    );

    $this->logger->info('Running Pagefind: @cmd', [
      '@cmd' => implode(' ', $command),
    ]);

    $process = new Process($command);
    // 5 minutes for large sites.
    $process->setTimeout(300);
    $process->run();

    $output = $process->getOutput() . $process->getErrorOutput();

    if (!$process->isSuccessful()) {
      return [
        'success' => FALSE,
        'output' => $output,
        'error' => "Pagefind exited with code {$process->getExitCode()}",
        'file_count' => $fileCount,
        'index_size' => NULL,
      ];
    }

    // Calculate index size.
    $indexSize = $this->calculateDirectorySize($outputDir);

    return [
      'success' => TRUE,
      'output' => $output,
      'error' => NULL,
      'file_count' => $fileCount,
      'index_size' => $this->formatBytes($indexSize),
    ];
  }

  /**
   * Check if the pagefind binary is available.
   *
   * @return array{available: bool, binary: ?string,
   *   version: ?string, via: string, message: string}
   *   The binary status array.
   */
  public function checkBinary(?string $configuredPath = NULL): array {
    $resolver = new PagefindBinary(
      configuredPath: $configuredPath,
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );
    return $resolver->status();
  }

  /**
   * Get stats about the current Pagefind index.
   *
   * No index size. This runs on every GET of the settings form, and
   * calculateDirectorySize() stat()s every file under the output directory to
   * produce it. On a site with a six-figure fragment count sitting on network
   * storage that is minutes of latency per page load -- measured at ~4 minutes
   * for 120k files over NFS, enough to hit max_execution_time. build() still
   * reports a size, where the walk is one-off and the files were just written.
   *
   * file_count avoids the same class of walk on the common path: it reads
   * page_count from pagefind-entry.json, which Pagefind already writes at
   * build time, rather than calling countFragments(), whose glob() of the
   * fragment directory scales with the corpus the same way
   * calculateDirectorySize() does. If pagefind-entry.json is missing or
   * unreadable that fallback still runs, and still pays the same glob().
   *
   * @return array{exists: bool, file_count: int, last_built: ?string}
   *   The index status array.
   */
  public function getStatus(string $outputDir): array {
    $location = is_dir($outputDir) ? $this->indexLocator->locate($outputDir) : NULL;
    if ($location === NULL) {
      return [
        'exists' => FALSE,
        'file_count' => 0,
        'last_built' => NULL,
      ];
    }

    $mtime = filemtime($location['indexFile']);
    $fileCount = $this->indexLocator->pageCount($location)
      ?? $this->indexLocator->countFragments($location);

    return [
      'exists' => TRUE,
      'file_count' => $fileCount,
      'last_built' => $mtime ? date('Y-m-d H:i:s', $mtime) : NULL,
    ];
  }

  /**
   * Calculate total size of a directory in bytes.
   */
  protected function calculateDirectorySize(string $dir): int {
    $size = 0;
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      $size += $file->getSize();
    }
    return $size;
  }

  /**
   * Format bytes to human-readable size.
   */
  protected function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $exp = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $exp = min($exp, count($units) - 1);
    return round($bytes / (1024 ** $exp), 1) . ' ' . $units[$exp];
  }

}
