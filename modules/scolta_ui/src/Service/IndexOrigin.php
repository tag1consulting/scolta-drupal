<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta_ui\Cache\DrupalCacheDriver;
use GuzzleHttp\ClientInterface;
use Tag1\Scolta\Index\IndexLocatorInterface;
use Tag1\Scolta\Index\LocalIndexLocator;
use Tag1\Scolta\Index\RemoteIndexLocator;

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

  /**
   * Constructs an IndexOrigin.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   Resolves the local output directory's stream wrapper.
   * @param \GuzzleHttp\ClientInterface|null $httpClient
   *   Checks a remote origin. Only locator() needs it.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   Where a remote origin's answer is reused from. Only locator() needs it.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly ?ClientInterface $httpClient = NULL,
    private readonly ?CacheBackendInterface $cache = NULL,
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
   * The scolta-php locator for the configured origin.
   *
   * For health reporting: scolta-php's HealthChecker asks it whether the
   * index is being served, by a file check for <local> and by fetching the
   * remote entry file for a URL. The remote answer is reused for
   * RemoteIndexLocator::DEFAULT_TTL seconds, because /health is anonymous.
   * Never ask it on a page-render path; exists() below is for that.
   *
   * @throws \LogicException
   *   When the origin is remote and this service was built without an HTTP
   *   client or a cache. The cache is not optional for a remote origin:
   *   without it every anonymous /health request makes more to that host.
   */
  public function locator(): IndexLocatorInterface {
    if (!$this->isRemote()) {
      return new LocalIndexLocator($this->resolvedOutputDir());
    }
    if ($this->httpClient === NULL || $this->cache === NULL) {
      throw new \LogicException('IndexOrigin needs an HTTP client and a cache to locate a remote index.');
    }
    return new RemoteIndexLocator(
      $this->origin(),
      $this->httpClient,
      new DrupalCacheDriver($this->cache),
    );
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
