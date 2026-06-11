<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

/**
 * Single source of truth for locating a built Pagefind index on disk.
 *
 * Three call sites previously disagreed on what "the index exists" means:
 * PagefindBuilder::getStatus() checked the legacy root pagefind.js,
 * HealthController and drush scolta:status checked pagefind/pagefind.js,
 * and the search block checked pagefind/pagefind-entry.json. They now all
 * resolve through this service. (Eventually this belongs upstream in
 * scolta-php's HealthChecker; kept local until that lands.)
 *
 * @since 1.0.4
 * @stability experimental
 */
class IndexLocator {

  /**
   * Locate the index inside a resolved output directory.
   *
   * Supports both the modern layout ($outputDir/pagefind/pagefind.js) and
   * the legacy root layout ($outputDir/pagefind.js). pagefind.js is the
   * load-bearing artifact the browser requests, so its presence defines
   * existence.
   *
   * @param string $outputDir
   *   The resolved (non-stream-wrapper) output directory.
   *
   * @return array{indexFile: string, fragmentDir: string, entryFile: string}|null
   *   Paths of the index artifacts, or NULL when no index exists.
   */
  public function locate(string $outputDir): ?array {
    $outputDir = rtrim($outputDir, '/');

    if (file_exists($outputDir . '/pagefind/pagefind.js')) {
      return [
        'indexFile' => $outputDir . '/pagefind/pagefind.js',
        'fragmentDir' => $outputDir . '/pagefind/fragment',
        'entryFile' => $outputDir . '/pagefind/pagefind-entry.json',
      ];
    }

    if (file_exists($outputDir . '/pagefind.js')) {
      return [
        'indexFile' => $outputDir . '/pagefind.js',
        'fragmentDir' => $outputDir . '/fragment',
        'entryFile' => $outputDir . '/pagefind-entry.json',
      ];
    }

    return NULL;
  }

  /**
   * Whether a built index exists in the given resolved output directory.
   */
  public function exists(string $outputDir): bool {
    return $this->locate($outputDir) !== NULL;
  }

  /**
   * Count fragment files for a located index.
   *
   * @param array{indexFile: string, fragmentDir: string, entryFile: string} $location
   *   A location returned by locate().
   */
  public function countFragments(array $location): int {
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- glob() for a simple count; scanDirectory() is heavier.
    return count(glob($location['fragmentDir'] . '/*') ?: []);
  }

}
