<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * composer.json must declare every dependency scolta.info.yml requires.
 *
 * The two failure modes this catches both let `composer require
 * tag1/scolta-drupal` succeed while `drush en scolta` fails with an
 * unresolved dependency error (issue #41): composer.json missing
 * drupal/search_api entirely, or a future .info.yml dependency added
 * without a matching composer.json entry.
 */
class ComposerDependencyParityTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
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
    $info = Yaml::parseFile($infoFile);
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

}
