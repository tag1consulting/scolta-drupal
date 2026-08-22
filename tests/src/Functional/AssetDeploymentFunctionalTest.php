<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\scolta\Service\AssetDeployer;
use Drupal\Tests\BrowserTestBase;

/**
 * Proves the browser bundle deploys from vendor and stays current.
 *
 * This is the behavioral replacement for the retired assets-in-sync CI job:
 * with no committed copies left there is no parity to byte-compare in CI, so
 * what must hold instead is that a real Drupal install ends up serving the
 * installed scolta-php's bundle, and that a cache rebuild repairs a stale or
 * damaged deployment. Staleness here is not hypothetical — it is exactly the
 * state every site is in right after `composer update` swaps scolta-php under
 * a previously deployed bundle.
 *
 * @group scolta
 */
class AssetDeploymentFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Install deploys every bundle file, byte-identical to the vendored source.
   */
  public function testInstallDeploysBundleFromVendor(): void {
    /** @var \Drupal\scolta\Service\AssetDeployer $deployer */
    $deployer = \Drupal::service('scolta.asset_deployer');
    $sourceDir = $deployer->sourceDir();
    $this->assertNotNull($sourceDir, 'The installed tag1/scolta-php must carry an assets/ directory.');

    foreach (AssetDeployer::ASSETS as $src => $dest) {
      $destUri = AssetDeployer::DIRECTORY . '/' . $dest;
      $this->assertFileExists($destUri, "hook_install() must have deployed {$dest}.");
      $this->assertSame(
        hash_file('sha256', $sourceDir . '/' . $src),
        hash_file('sha256', $destUri),
        "Deployed {$dest} must be byte-identical to the vendored {$src}."
      );
    }
  }

  /**
   * A cache rebuild repairs a stale deployed file; a current one is left be.
   */
  public function testCacheRebuildRedeploysStaleAssets(): void {
    $jsUri = AssetDeployer::DIRECTORY . '/js/scolta.js';
    $cssUri = AssetDeployer::DIRECTORY . '/css/scolta.css';

    // Simulate the post-composer-update state: the deployed copy no longer
    // matches the vendored canonical.
    file_put_contents($jsUri, '// stale bundle from a previous scolta-php');
    // Same size, different bytes: the comparison must hash, not just stat.
    $cssSize = filesize($cssUri);
    file_put_contents($cssUri, str_repeat('x', (int) $cssSize));
    $untouchedMtime = filemtime(AssetDeployer::DIRECTORY . '/wasm/scolta_core_bg.wasm');

    // What every deploy routine runs after composer update.
    drupal_flush_all_caches();

    /** @var \Drupal\scolta\Service\AssetDeployer $deployer */
    $deployer = \Drupal::service('scolta.asset_deployer');
    $sourceDir = $deployer->sourceDir();
    clearstatcache();
    $this->assertSame(
      hash_file('sha256', $sourceDir . '/js/scolta.js'),
      hash_file('sha256', $jsUri),
      'drush cr must redeploy a stale js/scolta.js.'
    );
    $this->assertSame(
      hash_file('sha256', $sourceDir . '/css/scolta.css'),
      hash_file('sha256', $cssUri),
      'drush cr must redeploy a same-size-but-different css/scolta.css.'
    );
    $this->assertSame(
      $untouchedMtime,
      filemtime(AssetDeployer::DIRECTORY . '/wasm/scolta_core_bg.wasm'),
      'A file already matching the vendored canonical must not be rewritten — pointless mtime churn reads as a change to CDN/rsync deploys.'
    );
  }

  /**
   * Uninstall removes the deployed directory.
   */
  public function testUninstallRemovesDeployedAssets(): void {
    $this->assertFileExists(AssetDeployer::DIRECTORY . '/js/scolta.js');
    \Drupal::service('module_installer')->uninstall(['scolta']);
    $this->assertDirectoryDoesNotExist(AssetDeployer::DIRECTORY);
  }

}
