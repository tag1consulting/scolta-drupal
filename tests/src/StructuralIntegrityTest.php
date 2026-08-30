<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates that service definitions, routes, and PHP files are consistent.
 *
 * These tests do not require a Drupal bootstrap — they verify that the
 * wiring in YAML files references PHP classes/methods that actually exist,
 * using parsed YAML and reflection.
 */
class StructuralIntegrityTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // Service classes exist.
  // -------------------------------------------------------------------

  public function testServiceClassFilesExist(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml');

    foreach ($services['services'] as $id => $def) {
      if (!isset($def['class'])) {
        continue; // logger.channel.scolta uses parent.
      }
      $classFile = $this->classToFile($def['class']);
      $this->assertFileExists(
        $classFile,
        "Service '{$id}' references class {$def['class']} but file does not exist"
      );
    }
  }

  public function testDrushCommandClassFileExists(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $class = $drush['services']['scolta.commands']['class'];
    $classFile = $this->classToFile($class);
    $this->assertFileExists($classFile,
      "Drush command class {$class} file does not exist");
  }

  // -------------------------------------------------------------------
  // Routing controller classes and methods exist.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
  public function testRouteControllerFileExists(string $routeName, string $controllerSpec): void {
    if (str_contains($controllerSpec, '::')) {
      [$class, $method] = explode('::', $controllerSpec);
    } else {
      $class = ltrim($controllerSpec, '\\');
      $method = null;
    }

    $classFile = $this->classToFile($class);
    $this->assertFileExists($classFile,
      "Route '{$routeName}' references {$class} but file does not exist");

    // method_exists() sees inherited methods too, so a handler that lives on
    // AiApiControllerBase (the shared AI request pipeline) also passes.
    if ($method) {
      $this->assertTrue(
        method_exists($class, $method),
        "Route '{$routeName}' references method {$method} not found on {$class} or its ancestors"
      );
    }
  }

  public static function routeProvider(): array {
    $root = dirname(__DIR__, 2);
    $routing = Yaml::parseFile($root . '/scolta.routing.yml');
    $routes = [];

    foreach ($routing as $name => $def) {
      if (isset($def['defaults']['_controller'])) {
        $routes[$name] = [$name, ltrim($def['defaults']['_controller'], '\\')];
      }
      if (isset($def['defaults']['_form'])) {
        $routes[$name] = [$name, ltrim($def['defaults']['_form'], '\\')];
      }
    }

    return $routes;
  }

  // -------------------------------------------------------------------
  // PHP use-statements reference classes that exist in scolta-php or Drupal.
  // -------------------------------------------------------------------

  public static function phpFileProvider(): \Generator {
    $root = dirname(__DIR__, 2);
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
      if ($file->getExtension() === 'php') {
        yield $file->getBasename() => [$file->getPathname()];
      }
    }
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('phpFileProvider')]
  public function testScoltaPhpImportsReferenceRealClasses(string $file): void {
    $scoltaPhpSrc = $this->resolveScoltaPhpSrc();
    if ($scoltaPhpSrc === null) {
      $this->markTestSkipped('scolta-php source not available at sibling or vendor path');
    }

    $contents = file_get_contents($file);

    // Extract all use statements referencing Tag1\Scolta.
    preg_match_all('/^use\s+(Tag1\\\\Scolta\\\\[^;]+);/m', $contents, $matches);

    if (empty($matches[1])) {
      // File does not import any Tag1\Scolta classes — nothing to check.
      $this->assertTrue(true);
      return;
    }

    foreach ($matches[1] as $fqcn) {
      // Convert FQCN to expected file path under scolta-php.
      $relative = str_replace('\\', '/', str_replace('Tag1\\Scolta\\', '', $fqcn));
      $expectedFile = $scoltaPhpSrc . $relative . '.php';

      $this->assertFileExists($expectedFile,
        "File {$file} imports {$fqcn} but {$expectedFile} does not exist");
    }
  }

  // -------------------------------------------------------------------
  // Service argument count matches constructor parameter count.
  // -------------------------------------------------------------------

  public function testServiceArgumentCountMatchesConstructor(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml');

    $classesToCheck = [
      'scolta.ai_service' => 'Drupal\scolta\Service\ScoltaAiService',
      'scolta.pagefind_exporter' => 'Drupal\scolta\Service\PagefindExporter',
      'scolta.pagefind_builder' => 'Drupal\scolta\Service\PagefindBuilder',
      'scolta.asset_deployer' => 'Drupal\scolta\Service\AssetDeployer',
    ];

    foreach ($classesToCheck as $serviceId => $className) {
      $argCount = count($services['services'][$serviceId]['arguments'] ?? []);
      $paramCount = (new \ReflectionMethod($className, '__construct'))
        ->getNumberOfParameters();

      $this->assertEquals(
        $paramCount, $argCount,
        "Service '{$serviceId}' has {$argCount} arguments but constructor has {$paramCount} parameters"
      );
    }
  }

  public function testDrushCommandArgumentCountMatchesConstructor(): void {
    // ScoltaCommands extends Drush\Commands\DrushCommands, and drush is a
    // require-dev dependency that the local unit-test vendor does not carry —
    // reflecting the class without it is a fatal, not a catchable failure.
    if (!class_exists('Drush\Commands\DrushCommands')) {
      $this->markTestSkipped('drush/drush not installed; ScoltaCommands cannot be reflected without its parent class.');
    }

    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $args = $drush['services']['scolta.commands']['arguments'] ?? [];
    $paramCount = (new \ReflectionMethod('Drupal\scolta\Commands\ScoltaCommands', '__construct'))
      ->getNumberOfParameters();

    $this->assertEquals($paramCount, count($args),
      "Drush command argument count mismatch");
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  private function resolveScoltaPhpSrc(): ?string {
    $candidates = [
      $this->moduleRoot . '/../scolta-php/src/',
      $this->moduleRoot . '/vendor/tag1/scolta-php/src/',
    ];
    foreach ($candidates as $path) {
      if (is_dir($path)) {
        return $path;
      }
    }
    return null;
  }

  private function classToFile(string $fqcn): string {
    // Drupal\scolta\Foo\Bar -> src/Foo/Bar.php
    $fqcn = ltrim($fqcn, '\\');
    $relative = str_replace('\\', '/', str_replace('Drupal\\scolta\\', '', $fqcn));
    return $this->moduleRoot . '/src/' . $relative . '.php';
  }

  // -------------------------------------------------------------------
  // PHPStan configuration
  // -------------------------------------------------------------------

  public function testPhpstanBaselineExists(): void {
    $this->assertFileExists($this->moduleRoot . '/phpstan-baseline.neon',
      'phpstan-baseline.neon must exist to track pre-existing errors');
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

  /**
   * scolta.info.yml must declare the version, since composer.json no longer
   * does. Removing both would leave the module with no version at all.
   */
  public function testInfoYmlDeclaresTheVersion(): void {
    $info = Yaml::parseFile($this->moduleRoot . '/scolta.info.yml');

    $this->assertArrayHasKey('version', $info,
      'scolta.info.yml is now the only place the module version is declared.');
    $this->assertNotEmpty($info['version']);
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

  /**
   * The search library must serve the deployed bundle.
   *
   * The library must reference public://scolta-assets, because vendor/ is not
   * web-accessible and the module directory is read-only on immutable-code
   * hosts. That the deployment itself happens (install, cache rebuild,
   * locale-safe path resolution) is covered behaviorally by
   * AssetDeploymentFunctionalTest and LocaleAssetPathFunctionalTest.
   *
   * @see \Drupal\Tests\scolta\Functional\AssetDeploymentFunctionalTest
   * @see \Drupal\Tests\scolta\Functional\LocaleAssetPathFunctionalTest
   */
  public function testSearchLibraryServesDeployedAssets(): void {
    $libraries = Yaml::parseFile($this->moduleRoot . '/scolta.libraries.yml');
    $searchJs = array_keys($libraries['search']['js'] ?? []);
    $searchCss = array_keys($libraries['search']['css']['theme'] ?? []);
    $this->assertSame(['public://scolta-assets/js/scolta.js'], $searchJs,
      'The search library JS must be the deployed public://scolta-assets copy.');
    $this->assertSame(['public://scolta-assets/css/scolta.css'], $searchCss,
      'The search library CSS must be the deployed public://scolta-assets copy.');
  }

}
