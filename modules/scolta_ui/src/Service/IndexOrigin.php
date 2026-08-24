<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

/**
 * Where the index this site searches actually lives.
 *
 * The frontend can search an index built on this site or one owned by
 * another, so "where is the index" stops being a filesystem question and
 * becomes a question about the configured origin. Every frontend consumer
 * asks this rather than reaching for a path itself.
 *
 * On the local path this reads `scolta.settings:pagefind.output_dir`, which
 * the scolta module owns. That is deliberate and is not a module dependency:
 * Drupal config is global, so reading another module's config object by name
 * works whether or not that module is installed, and the default below covers
 * the frontend-only install where nothing owns that object.
 */
class IndexOrigin {

  /**
   * The value meaning "the index this site builds".
   *
   * Angle brackets follow Drupal's convention for a reserved value in a field
   * that otherwise holds a real one, as with <front> and <nolink>.
   */
  public const LOCAL = '<local>';

  /**
   * Default output directory, used when scolta is not installed here.
   */
  private const DEFAULT_OUTPUT_DIR = 'public://scolta-pagefind';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
  ) {}

  /**
   * The configured origin: <local>, or an absolute URL.
   */
  public function origin(): string {
    $value = trim((string) ($this->configFactory->get('scolta_ui.settings')->get('index_origin') ?? ''));
    return $value === '' ? self::LOCAL : rtrim($value, '/');
  }

  /**
   * Whether the index is served by another site.
   */
  public function isRemote(): bool {
    return $this->origin() !== self::LOCAL;
  }

  /**
   * The remote origin URL, or NULL when the index is local.
   */
  public function remoteBase(): ?string {
    return $this->isRemote() ? $this->origin() : NULL;
  }

  /**
   * The stream-wrapper URI of the local build output.
   */
  public function outputDirUri(): string {
    return $this->configFactory->get('scolta.settings')->get('pagefind.output_dir')
      ?? self::DEFAULT_OUTPUT_DIR;
  }

  /**
   * The local build output as a filesystem path.
   */
  public function resolvedOutputDir(): string {
    $uri = $this->outputDirUri();
    if (!str_contains($uri, '://')) {
      return $uri;
    }
    try {
      $wrapper = $this->streamWrapperManager->getViaUri($uri);
      return $wrapper ? ($wrapper->realpath() ?: $uri) : $uri;
    }
    catch (\Exception) {
      return $uri;
    }
  }

  /**
   * Locate the local index artifacts, or NULL when there is no local index.
   *
   * @return array{indexFile: string, fragmentDir: string, entryFile: string}|null
   *   Paths of the index artifacts, or NULL.
   */
  public function locateLocal(): ?array {
    $dir = rtrim($this->resolvedOutputDir(), '/');

    if (file_exists($dir . '/pagefind/pagefind.js')) {
      return [
        'indexFile' => $dir . '/pagefind/pagefind.js',
        'fragmentDir' => $dir . '/pagefind/fragment',
        'entryFile' => $dir . '/pagefind/pagefind-entry.json',
      ];
    }

    if (file_exists($dir . '/pagefind.js')) {
      return [
        'indexFile' => $dir . '/pagefind.js',
        'fragmentDir' => $dir . '/fragment',
        'entryFile' => $dir . '/pagefind-entry.json',
      ];
    }

    return NULL;
  }

  /**
   * Enumerate fragment files for a located local index.
   *
   * @param array{indexFile: string, fragmentDir: string, entryFile: string} $location
   *   A location returned by locateLocal().
   *
   * @return string[]
   *   The fragment file paths, or an empty array when none exist.
   */
  public function fragmentFiles(array $location): array {
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- glob() to enumerate fragments; scanDirectory() is heavier.
    return glob($location['fragmentDir'] . '/*') ?: [];
  }

  /**
   * Whether there is an index to search.
   *
   * A remote origin answers TRUE without a request. This is asked while
   * building a block on every page render, and a synchronous HTTP round trip
   * there would put another site's latency on this site's critical path. The
   * browser reports a missing remote index when it tries to load it, and the
   * health endpoint checks reachability properly.
   */
  public function exists(): bool {
    return $this->isRemote() || $this->locateLocal() !== NULL;
  }

}
