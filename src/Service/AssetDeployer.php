<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Composer\InstalledVersions;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Deploys the scolta-php browser bundle into the public files directory.
 *
 * The bundle (JS, CSS, WASM) is canonical in tag1/scolta-php's assets/
 * directory, which lives under vendor/ and is therefore not web-accessible.
 * This service copies it to public://scolta-assets, which every host can
 * write — unlike the module directory, which is read-only on
 * immutable-code deploys (Pantheon, Acquia production, read-only container
 * images) — and which every webserver serves.
 *
 * deploy() is idempotent and cheap when nothing changed: each file is
 * compared against the vendored canonical (size first, then hash) and
 * copied only when it differs. It runs at module install and on every cache
 * rebuild (hook_rebuild()), so `drush cr` after `composer update` — the
 * step every deploy already performs — picks up a new scolta-php bundle
 * with no module release and no re-vendor commit. No version bookkeeping is
 * kept in state: comparing the files themselves also self-heals a wiped or
 * partially restored public files directory, which a recorded version
 * number would wave through.
 */
class AssetDeployer {

  /**
   * The directory the bundle is deployed to.
   */
  public const DIRECTORY = 'public://scolta-assets';

  /**
   * Bundle files: path under scolta-php assets/ => path under DIRECTORY.
   */
  public const ASSETS = [
    'js/scolta.js' => 'js/scolta.js',
    'css/scolta.css' => 'css/scolta.css',
    'wasm/scolta_core.js' => 'wasm/scolta_core.js',
    'wasm/scolta_core_bg.wasm' => 'wasm/scolta_core_bg.wasm',
  ];

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected LoggerInterface $logger,
    protected StreamWrapperManagerInterface $streamWrapperManager,
  ) {
  }

  /**
   * Copy every bundle file that differs from the vendored canonical.
   *
   * Missing sources are logged and skipped rather than thrown: an asset
   * problem must never take down a cache rebuild, and the warning names the
   * exact path an operator needs.
   *
   * @since 1.3.1
   * @stability stable
   */
  public function deploy(): void {
    $assetsDir = $this->sourceDir();
    if ($assetsDir === NULL) {
      $this->logger->warning('scolta-php assets not found under vendor — search JS/CSS/WASM unavailable.');
      return;
    }

    foreach (self::ASSETS as $src => $dest) {
      $srcPath = $assetsDir . '/' . $src;
      if (!file_exists($srcPath)) {
        $this->logger->warning('Missing scolta-php asset: @path', ['@path' => $srcPath]);
        continue;
      }

      $destUri = self::DIRECTORY . '/' . $dest;
      if ($this->isCurrent($srcPath, $destUri)) {
        continue;
      }

      $destDir = $this->fileSystem->dirname($destUri);
      if (!$this->fileSystem->prepareDirectory($destDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        $this->logger->error('Could not prepare @dir for the Scolta browser bundle.', ['@dir' => $destDir]);
        continue;
      }
      // Copy to a temp name and rename into place. The rename is atomic on
      // the same filesystem, so a client fetching mid-deploy — or a rebuild
      // killed mid-copy — never sees a truncated file at the served path.
      // The temp name is unique per process so two concurrent rebuilds
      // cannot interleave writes into one file; last rename wins, and both
      // rename the same bytes. A temp file orphaned by a kill is inert junk:
      // nothing serves it, the next deploy ignores it, and uninstall removes
      // the directory.
      $tmpUri = $destUri . '.tmp-' . uniqid('', TRUE);
      try {
        $this->fileSystem->copy($srcPath, $tmpUri, FileExists::Replace);
        $this->fileSystem->move($tmpUri, $destUri, FileExists::Replace);
      }
      catch (\Exception $e) {
        $this->logger->error('Could not deploy Scolta asset @dest: @message', [
          '@dest' => $destUri,
          '@message' => $e->getMessage(),
        ]);
        if (file_exists($tmpUri)) {
          $this->fileSystem->delete($tmpUri);
        }
        continue;
      }
      $this->logger->info('Deployed Scolta asset @dest from @src.', ['@dest' => $destUri, '@src' => $srcPath]);
    }
  }

  /**
   * Resolve a deployed asset URI to a path the asset system reads as local.
   *
   * Library definitions accept stream-wrapper URIs natively, but a colon in
   * a JS path is fatal on a multilingual site: locale's hook_js_alter()
   * hands every file asset to _locale_parse_js_file(), which throws on any
   * path containing one. Declaring the bundle as public://scolta-assets/...
   * therefore returned HTTP 500 on every rendered page of a site with the
   * locale module enabled, while the API routes — which never run the JS
   * translation scan — kept answering 200.
   *
   * What locale accepts is the shape core uses for its own library files: a
   * path relative to DRUPAL_ROOT. This returns that path with a leading
   * slash, which is what makes LibraryDiscoveryParser treat it as
   * root-relative rather than relative to this module's directory; the
   * parser strips the slash, and base_path() is applied later at render
   * time. That is also why the file URL generator is the wrong tool here:
   * generateString() has base_path() baked in already, so on a
   * subdirectory install its prefix would be applied twice.
   *
   * Resolution goes through the wrapper rather than a hardcoded
   * sites/default/files, so a site that relocates its public files
   * directory via $settings['file_public_path'] is followed automatically.
   *
   * @param string $uri
   *   A stream-wrapper URI, normally one under DIRECTORY.
   *
   * @return string|null
   *   A DRUPAL_ROOT-relative path with a leading slash, or NULL when the
   *   URI's wrapper is not a local filesystem one (an S3-backed public://,
   *   say) and so has no such path.
   *
   * @since 1.3.1
   * @stability internal
   */
  public function webPath(string $uri): ?string {
    $wrapper = $this->streamWrapperManager->getViaUri($uri);
    if (!$wrapper instanceof LocalStream) {
      return NULL;
    }
    $directory = trim($wrapper->getDirectoryPath(), '/');
    $target = StreamWrapperManager::getTarget($uri);
    if ($directory === '' || $target === FALSE || $target === '') {
      return NULL;
    }
    return '/' . $directory . '/' . $target;
  }

  /**
   * Remove the deployed bundle. Called from hook_uninstall().
   *
   * @since 1.3.1
   * @stability stable
   */
  public function remove(): void {
    $this->fileSystem->deleteRecursive(self::DIRECTORY);
  }

  /**
   * Locate the assets/ directory of the installed tag1/scolta-php.
   *
   * @return string|null
   *   The absolute path, or NULL when the package or its assets are absent.
   *
   * @since 1.3.1
   * @stability internal
   */
  public function sourceDir(): ?string {
    if (!class_exists(InstalledVersions::class)) {
      return NULL;
    }
    try {
      $path = InstalledVersions::getInstallPath('tag1/scolta-php');
    }
    catch (\OutOfBoundsException $e) {
      return NULL;
    }
    if ($path === NULL) {
      return NULL;
    }
    $assetsDir = rtrim($path, '/') . '/assets';
    return is_dir($assetsDir) ? $assetsDir : NULL;
  }

  /**
   * Whether the deployed copy is byte-identical to the vendored canonical.
   *
   * Size is compared first so the common no-change case costs two stat()
   * calls; the hash runs only on a size match, and the files are small
   * enough that even that is negligible on a cache rebuild. The comparison
   * is between the two files themselves — never a recorded version — so a
   * missing, truncated, or hand-edited deployed copy always reads as stale.
   */
  protected function isCurrent(string $srcPath, string $destUri): bool {
    if (!file_exists($destUri)) {
      return FALSE;
    }
    if (filesize($srcPath) !== filesize($destUri)) {
      return FALSE;
    }
    return hash_file('xxh3', $srcPath) === hash_file('xxh3', $destUri);
  }

}
