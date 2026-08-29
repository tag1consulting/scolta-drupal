<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the scolta-core → scolta-php rename is fully propagated.
 *
 * Checks the structural artifacts of the rename: composer.json, .info.yml
 * dependencies, and the deployed asset bundle.
 */
class RenameIntegrityTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  public function testComposerJsonRequiresScoltaPhp(): void {
    $composerFile = $this->moduleRoot . '/composer.json';
    if (!file_exists($composerFile)) {
      $this->markTestSkipped('No composer.json in scolta-drupal');
    }

    $composer = json_decode(file_get_contents($composerFile), true);
    $this->assertArrayHasKey('tag1/scolta-php', $composer['require'] ?? [],
      "composer.json should require tag1/scolta-php");
    $this->assertArrayNotHasKey('tag1/scolta-core', $composer['require'] ?? [],
      "composer.json should not require tag1/scolta-core");
    $this->assertArrayNotHasKey('tag1/scolta', $composer['require'] ?? [],
      "composer.json should not require tag1/scolta (old name)");
  }

  /**
   * composer.json must declare drupal/search_api as a runtime dependency.
   *
   * The module's .info.yml depends on search_api:search_api, so Composer must
   * pull it in transitively — otherwise `composer require tag1/scolta-drupal`
   * succeeds but `drush en scolta` fails with an unresolved dependency error.
   * See issue #41.
   */
  public function testComposerJsonRequiresDrupalSearchApi(): void {
    $composerFile = $this->moduleRoot . '/composer.json';
    if (!file_exists($composerFile)) {
      $this->markTestSkipped('No composer.json in scolta-drupal');
    }

    $composer = json_decode(file_get_contents($composerFile), true);
    $require = $composer['require'] ?? [];

    $this->assertArrayHasKey(
      'drupal/search_api',
      $require,
      'composer.json must require drupal/search_api — the module declares it in .info.yml dependencies, '
      . 'so Composer must pull it in transitively to allow `drush en scolta` without manual steps (issue #41)'
    );

    $constraint = $require['drupal/search_api'];
    $this->assertMatchesRegularExpression(
      '/^\^?\d+\.\d+/',
      $constraint,
      'drupal/search_api version constraint must be a valid semver constraint'
    );
  }

  /**
   * Every module-level Drupal dependency in .info.yml must have a matching
   * Composer package in composer.json require (excluding drupal/core packages
   * which are required implicitly as part of the Drupal project).
   */
  public function testInfoYmlDependenciesHaveComposerEntries(): void {
    $infoFile = $this->moduleRoot . '/scolta.info.yml';
    if (!file_exists($infoFile)) {
      $this->markTestSkipped('scolta.info.yml not found');
    }

    $info = \Symfony\Component\Yaml\Yaml::parseFile($infoFile);
    $dependencies = $info['dependencies'] ?? [];

    $composerFile = $this->moduleRoot . '/composer.json';
    $composer = json_decode(file_get_contents($composerFile), true);
    $require = $composer['require'] ?? [];

    // Drupal dependency format: "project:module" where project is the
    // Drupal.org project name. Core modules (drupal:node, drupal:user, etc.)
    // ship with drupal/core and don't need a separate Composer entry.
    // Third-party modules (e.g. search_api:search_api) are installed as
    // drupal/<project> Composer packages.
    foreach ($dependencies as $dep) {
      $project = explode(':', $dep, 2)[0];
      if ($project === 'drupal') {
        // Core-bundled module — no separate Composer package needed.
        continue;
      }
      $composerPackage = 'drupal/' . $project;
      $this->assertArrayHasKey(
        $composerPackage,
        $require,
        "Module dependency '{$dep}' from .info.yml must have a matching Composer entry for '{$composerPackage}' (issue #41)"
      );
    }
  }

  /**
   * Verify the scolta.js bundle is present in the installed scolta-php.
   *
   * The bundle is not committed to this module: AssetDeployer copies it from
   * vendor into the public files directory at install and cache rebuild.
   */
  public function testScoltaJsExists(): void {
    $jsFile = \Composer\InstalledVersions::getInstallPath('tag1/scolta-php') . '/assets/js/scolta.js';
    $this->assertFileExists($jsFile,
      'scolta.js must exist in the installed scolta-php assets');
  }

  /**
   * Verify the scolta.css bundle is present in the installed scolta-php.
   */
  public function testScoltaCssExists(): void {
    $cssFile = \Composer\InstalledVersions::getInstallPath('tag1/scolta-php') . '/assets/css/scolta.css';
    $this->assertFileExists($cssFile,
      'scolta.css must exist in the installed scolta-php assets');
  }

  /**
   * Verify scolta-php's composer.json has the correct package name.
   */
  public function testScoltaPhpPackageNameIsCorrect(): void {
    $scoltaPhpRoot = $this->resolveScoltaPhpRoot();
    if ($scoltaPhpRoot === null) {
      $this->markTestSkipped('scolta-php not available at sibling or vendor path');
    }

    $composerFile = $scoltaPhpRoot . '/composer.json';
    $this->assertFileExists($composerFile, 'scolta-php/composer.json must exist');

    $composer = json_decode(file_get_contents($composerFile), true);
    $this->assertEquals('tag1/scolta-php', $composer['name'],
      "scolta-php composer.json name should be tag1/scolta-php");
  }

  /**
   * Resolve the scolta-php root directory (sibling path repo or vendor).
   */
  private function resolveScoltaPhpRoot(): ?string {
    $candidates = [
      $this->moduleRoot . '/../scolta-php',
      $this->moduleRoot . '/vendor/tag1/scolta-php',
    ];
    foreach ($candidates as $path) {
      if (is_dir($path)) {
        return $path;
      }
    }
    return null;
  }

}
