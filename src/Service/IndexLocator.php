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
   * Enumerate fragment files for a located index.
   *
   * The single owner of the fragment-directory glob. Consumed by
   * countFragments() (count only) and HealthController (count plus the file
   * list for its integrity check), so the glob pattern lives in one place.
   *
   * @param array{indexFile: string, fragmentDir: string, entryFile: string} $location
   *   A location returned by locate().
   *
   * @return string[]
   *   The fragment file paths, or an empty array when none exist.
   */
  public function fragmentFiles(array $location): array {
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- glob() to enumerate fragments; scanDirectory() is heavier.
    return glob($location['fragmentDir'] . '/*') ?: [];
  }

  /**
   * Count fragment files for a located index.
   *
   * @param array{indexFile: string, fragmentDir: string, entryFile: string} $location
   *   A location returned by locate().
   */
  public function countFragments(array $location): int {
    return count($this->fragmentFiles($location));
  }

  /**
   * Read the indexed page count from pagefind-entry.json.
   *
   * Pagefind records "page_count" per language in this file at build time,
   * so it answers the same question as countFragments() with a single small
   * JSON read instead of a glob() of the fragment directory -- minutes-slow
   * once a corpus reaches six figures on NFS (see
   * PagefindBuilder::getStatus()).
   *
   * @param array{indexFile: string, fragmentDir: string, entryFile: string} $location
   *   A location returned by locate().
   *
   * @return int|null
   *   The total page count across languages, or NULL if the entry file is
   *   missing or unreadable.
   */
  public function pageCount(array $location): ?int {
    $contents = @file_get_contents($location['entryFile']);
    if ($contents === FALSE) {
      return NULL;
    }
    $data = json_decode($contents, TRUE);
    if (!is_array($data['languages'] ?? NULL)) {
      return NULL;
    }
    $total = 0;
    foreach ($data['languages'] as $language) {
      $total += (int) ($language['page_count'] ?? 0);
    }
    return $total;
  }

}
