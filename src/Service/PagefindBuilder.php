<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

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
   * @return array{success: bool, output: string, error: ?string, file_count: ?int, index_size: ?string}
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

    // Count HTML files to provide a sanity check.
    $htmlFiles = glob($buildDir . '/*.html');
    $fileCount = $htmlFiles ? count($htmlFiles) : 0;

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
      mkdir($outputDir, 0755, TRUE);
    }

    // Build the pagefind command.
    // Handle both "pagefind" (direct binary) and "npx pagefind" (via npm).
    $parts = explode(' ', $binary, 2);
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
    $process->setTimeout(300); // 5 minutes for large sites.
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
   * @return array{available: bool, binary: ?string, version: ?string, via: string, message: string}
   */
  public function checkBinary(?string $configuredPath = null): array {
    $resolver = new \Tag1\Scolta\Binary\PagefindBinary(
      configuredPath: $configuredPath,
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );
    return $resolver->status();
  }

  /**
   * Get stats about the current Pagefind index.
   *
   * @return array{exists: bool, file_count: int, index_size: string, last_built: ?string}
   */
  public function getStatus(string $outputDir): array {
    if (!is_dir($outputDir)) {
      return [
        'exists' => FALSE,
        'file_count' => 0,
        'index_size' => '0 B',
        'last_built' => NULL,
      ];
    }

    // Check for pagefind.js as the indicator the index exists.
    $indexFile = $outputDir . '/pagefind.js';
    if (!file_exists($indexFile)) {
      return [
        'exists' => FALSE,
        'file_count' => 0,
        'index_size' => '0 B',
        'last_built' => NULL,
      ];
    }

    $size = $this->calculateDirectorySize($outputDir);
    $mtime = filemtime($indexFile);

    return [
      'exists' => TRUE,
      'file_count' => count(glob($outputDir . '/fragment/*') ?: []),
      'index_size' => $this->formatBytes($size),
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
