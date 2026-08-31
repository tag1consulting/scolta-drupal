<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Packaging invariants with a documented incident behind each one.
 *
 * These are the three checks in this file's former, much larger set that
 * pin a real thing that actually broke, rather than a fact a passing build
 * already guarantees.
 */
class StructuralIntegrityTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // .gitattributes covers dev files
  // -------------------------------------------------------------------

  public function testGitattributesExcludesDevFiles(): void {
    $path = $this->moduleRoot . '/.gitattributes';
    $this->assertFileExists($path,
      '.gitattributes must exist to exclude dev files from distribution archives');

    // Parse "path export-ignore" pairs line by line.
    $ignored = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $parts = preg_split('/\s+/', trim($line));
      if (count($parts) >= 2 && in_array('export-ignore', $parts, TRUE)) {
        $ignored[] = $parts[0];
      }
    }

    foreach (['/tests/', '/.github/', '/phpstan.neon'] as $devPath) {
      $this->assertContains($devPath, $ignored,
        ".gitattributes must mark {$devPath} export-ignore so it stays out of distribution archives");
    }
  }

  // -------------------------------------------------------------------
  // The module version comes from scolta.info.yml, not composer.json.
  // -------------------------------------------------------------------

  /**
   * composer.json must not declare a "version".
   *
   * A declared version overrides the one Composer derives from the branch or
   * tag, which is what the extra.branch-alias beside it exists to describe.
   * Packagist ignores the declared string; the drupal.org Composer facade
   * does not, so drupal/scolta presented itself as a fixed "1.0.6-dev"
   * whatever branch it was built from. A consuming site constrained to
   * dev-1.0.x could `composer update` (Composer knows the provenance of what
   * it just fetched) but the resulting lock recorded "1.0.6-dev", and
   * `composer install` compares only that recorded string against the
   * constraint — so it failed on every clean checkout. That broke a client's
   * CI on 2026-07-27 while the site installed fine on developer machines.
   */
  public function testComposerJsonDeclaresNoVersion(): void {
    $composer = json_decode(file_get_contents($this->moduleRoot . '/composer.json'), TRUE);

    $this->assertArrayNotHasKey('version', $composer,
      'composer.json must not declare a version. scolta.info.yml is the source ' .
      'of the module version (drupal.org injects the release version there at ' .
      'packaging time); extra.branch-alias describes the dev-main mapping.');
  }

  // -------------------------------------------------------------------
  // The browser bundle deploys from vendor; no copy may be committed.
  // -------------------------------------------------------------------

  /**
   * No copy of the scolta-php browser bundle may be committed here.
   *
   * The bundle is canonical in scolta-php's assets/ and is deployed to
   * public://scolta-assets by AssetDeployer, at install time and on every
   * cache rebuild. A committed copy would resurrect the retired bug class:
   * it goes stale the moment scolta-php's bundle changes, it needs a
   * re-vendor commit (and a CI parity gate) to stay honest, and whichever of
   * the two copies actually got served would be an accident of deployment
   * order. The copy-assets composer script goes with it — with nothing
   * committed there is nothing to re-vendor.
   */
  public function testNoBrowserBundleFilesAreCommitted(): void {
    $bundle = [
      'js/scolta.js',
      'css/scolta.css',
      'js/wasm/scolta_core.js',
      'js/wasm/scolta_core_bg.wasm',
    ];
    foreach ($bundle as $path) {
      $this->assertFileDoesNotExist($this->moduleRoot . '/' . $path,
        "{$path} must not be committed: the bundle deploys from the installed " .
        'tag1/scolta-php via AssetDeployer, and a committed copy goes stale.');
    }

    $composer = json_decode(file_get_contents($this->moduleRoot . '/composer.json'), TRUE);
    $this->assertArrayNotHasKey('copy-assets', $composer['scripts'] ?? [],
      'composer.json must not carry a copy-assets script: nothing is committed, so there is nothing to re-vendor.');
  }

}
