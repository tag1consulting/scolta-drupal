<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The boundary between the two modules this package ships.
 *
 * Either module must run alone. The functional install tests prove that for
 * the code paths they exercise; these two properties fail on paths nobody
 * exercises locally, so they are checked across the whole package instead.
 */
class ModuleBoundaryTest extends TestCase {

  /**
   * Every route a module names in code must be one it defines itself.
   *
   * A Url::fromRoute() naming the other module's route breaks a single-module
   * install in the way that is hardest to notice: the call throws
   * RouteNotFoundException, so the page 500s, but only on the install
   * combination nobody runs locally. DismissRebuildNoticeController once
   * redirected to scolta.settings — the frontend's — so on an index-builder
   * install dismissing the rebuild notice returned a 500.
   *
   * Core's own routes are exempt: those exist wherever Drupal does.
   *
   * @dataProvider moduleSourceProvider
   */
  public function testAModuleNamesOnlyItsOwnRoutes(string $module, array $files, array $ownRoutes): void {
    foreach ($files as $relative => $source) {
      preg_match_all("/fromRoute\(\s*'([a-z0-9_.]+)'/i", $source, $matches);

      foreach (array_unique($matches[1]) as $routeName) {
        if (!str_starts_with($routeName, 'scolta')) {
          continue;
        }
        $this->assertContains(
          $routeName,
          $ownRoutes,
          "{$relative} builds a URL for '{$routeName}', which {$module} does not define: on an install without the other module Url::fromRoute() throws and the request 500s"
        );
      }
    }
  }

  /**
   * Each module's own source files and the routes it defines.
   */
  public static function moduleSourceProvider(): array {
    $manifests = PackageManifest::each('routing');
    $all = PackageManifest::sourceFiles() + PackageManifest::proceduralFiles();

    $owned = [
      'scolta' => ['src/', 'scolta.module', 'scolta.install'],
      'scolta_ui' => ['modules/scolta_ui/'],
    ];

    $cases = [];
    foreach ($owned as $module => $prefixes) {
      $files = [];
      foreach ($all as $relative => $source) {
        foreach ($prefixes as $prefix) {
          if (str_starts_with($relative, $prefix)) {
            $files[$relative] = $source;
            break;
          }
        }
      }
      $cases[$module] = [$module, $files, array_keys($manifests[$module] ?? [])];
    }

    return $cases;
  }

  /**
   * A kernel or functional test may not reach into the unit suite's namespace.
   *
   * The suites are autoloaded by different things. Unit tests run against this
   * package's own composer autoloader, where autoload-dev maps
   * Drupal\scolta\Tests to tests/src. Kernel and functional tests run inside a
   * Drupal site where this package is a dependency, and composer never
   * registers a dependency's autoload-dev — so every class in that namespace
   * is absent there, and the test errors on its first line, in CI only.
   * PackageManifest is the class this is most likely to happen with.
   */
  public function testNoIntegrationTestImportsTheUnitSuiteNamespace(): void {
    $found = [];
    foreach (['Functional', 'Kernel'] as $suite) {
      foreach (glob(PackageManifest::root() . "/tests/src/{$suite}/*.php") ?: [] as $file) {
        // Tokenized, so a docblock that cross-references a unit test by its
        // fully-qualified name is prose and not a dependency.
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
          if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], TRUE)) {
            continue;
          }
          $text = is_array($token) ? $token[1] : $token;
          if (str_contains($text, 'scolta\\Tests') || $text === 'PackageManifest') {
            $found[] = "{$suite}/" . basename($file);
            break;
          }
        }
      }
    }

    $this->assertSame(
      [],
      $found,
      'These tests name the unit suite namespace, which does not autoload inside a Drupal site: ' . implode(', ', $found)
    );
  }

}
