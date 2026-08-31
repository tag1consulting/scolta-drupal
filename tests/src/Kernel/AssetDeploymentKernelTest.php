<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Service\AssetDeployer;

/**
 * Proves the browser bundle deploys from vendor and stays current.
 *
 * This is the behavioral replacement for the retired assets-in-sync CI job:
 * with no committed copies left there is no parity to byte-compare in CI, so
 * what must hold instead is that a real Drupal install ends up serving the
 * installed scolta-php's bundle, and that a cache rebuild repairs a stale or
 * damaged deployment. Staleness here is not hypothetical — it is exactly the
 * state every site is in right after `composer update` swaps scolta-php
 * under a previously deployed bundle. Install, drupal_flush_all_caches(),
 * and module uninstall are all real container operations with no HTTP
 * request involved, so this needs only KernelTestBase.
 *
 * @group scolta
 */
class AssetDeploymentKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta']);
    // scolta_uninstall() deletes per-user data via user.data, which needs
    // the users_data table KernelTestBase does not install just because
    // the 'user' module is enabled.
    $this->installSchema('user', ['users_data']);
  }

  /**
   * Install deploys every bundle file, byte-identical to the vendored source.
   */
  public function testInstallDeploysBundleFromVendor(): void {
    /** @var \Drupal\scolta\Service\AssetDeployer $deployer */
    $deployer = \Drupal::service('scolta.asset_deployer');
    $sourceDir = $deployer->sourceDir();
    $this->assertNotNull($sourceDir, 'The installed tag1/scolta-php must carry an assets/ directory.');

    // Kernel tests do not run hook_install(): the module is enabled via
    // $modules, not installed, so the deploy that normally happens at
    // install time has not happened yet. Calling the service directly is
    // what hook_install() itself does; verifying that call's effect is the
    // behavior this test is about.
    $deployer->deploy();

    foreach (AssetDeployer::ASSETS as $src => $dest) {
      $destUri = AssetDeployer::DIRECTORY . '/' . $dest;
      $this->assertFileExists($destUri, "The deployer must have deployed {$dest}.");
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
    /** @var \Drupal\scolta\Service\AssetDeployer $deployer */
    $deployer = \Drupal::service('scolta.asset_deployer');
    $deployer->deploy();

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

    $sourceDir = $deployer->sourceDir();
    clearstatcache();
    $this->assertSame(
      hash_file('sha256', $sourceDir . '/js/scolta.js'),
      hash_file('sha256', $jsUri),
      'A cache rebuild must redeploy a stale js/scolta.js.'
    );
    $this->assertSame(
      hash_file('sha256', $sourceDir . '/css/scolta.css'),
      hash_file('sha256', $cssUri),
      'A cache rebuild must redeploy a same-size-but-different css/scolta.css.'
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
    /** @var \Drupal\scolta\Service\AssetDeployer $deployer */
    $deployer = \Drupal::service('scolta.asset_deployer');
    $deployer->deploy();
    $this->assertFileExists(AssetDeployer::DIRECTORY . '/js/scolta.js');

    \Drupal::service('module_installer')->uninstall(['scolta']);

    $this->assertDirectoryDoesNotExist(AssetDeployer::DIRECTORY);
  }

}
