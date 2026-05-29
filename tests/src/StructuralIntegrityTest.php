<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates that service definitions, routes, and PHP files are consistent.
 *
 * These tests do not require a Drupal bootstrap — they verify that the
 * wiring in YAML files references PHP classes/methods that actually exist.
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

    // Verify the method exists in the file source.
    if ($method) {
      $contents = file_get_contents($classFile);
      $this->assertStringContainsString(
        "function {$method}(",
        $contents,
        "Route '{$routeName}' references method {$method} not found in {$class}"
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
    ];

    foreach ($classesToCheck as $serviceId => $className) {
      $argCount = count($services['services'][$serviceId]['arguments'] ?? []);
      $classFile = $this->classToFile($className);
      $contents = file_get_contents($classFile);

      // Count constructor parameters by looking for the function signature.
      if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $contents, $m)) {
        $params = array_filter(array_map('trim', explode(',', $m[1])));
        $paramCount = count($params);

        $this->assertEquals(
          $paramCount, $argCount,
          "Service '{$serviceId}' has {$argCount} arguments but constructor has {$paramCount} parameters"
        );
      }
    }
  }

  public function testDrushCommandArgumentCountMatchesConstructor(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $args = $drush['services']['scolta.commands']['arguments'] ?? [];
    $file = $this->classToFile('Drupal\scolta\Commands\ScoltaCommands');
    $contents = file_get_contents($file);

    if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $contents, $m)) {
      $params = array_filter(array_map('trim', explode(',', $m[1])));
      $this->assertEquals(count($params), count($args),
        "Drush command argument count mismatch");
    }
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
  // Release workflow ZIP folder structure
  // -------------------------------------------------------------------

  public function testReleaseWorkflowCreatesCorrectZipFolder(): void {
    $workflow = file_get_contents($this->moduleRoot . '/.github/workflows/release.yml');
    $this->assertStringContainsString(
      'PKG="scolta-drupal"',
      $workflow,
      'Release workflow must set PKG to scolta-drupal for the zip folder name'
    );
    $this->assertStringNotContainsString(
      'zip -r ../scolta-drupal-${VERSION}.zip .',
      $workflow,
      'Must not zip from current dir (creates flat archive without scolta-drupal/ folder)'
    );
  }

  // -------------------------------------------------------------------
  // isExecutable() guard
  // -------------------------------------------------------------------

  public function testCommandsDoNotCallIsExecutable(): void {
    $source = file_get_contents($this->moduleRoot . '/src/Commands/ScoltaCommands.php');
    $this->assertStringNotContainsString(
      'isExecutable()',
      $source,
      'Drush commands must not call private isExecutable(); use resolve() + status() instead'
    );
  }

  // -------------------------------------------------------------------
  // PHPStan configuration
  // -------------------------------------------------------------------

  public function testPhpstanConfigIncludesDeprecationRules(): void {
    $neon = file_get_contents($this->moduleRoot . '/phpstan.neon');
    $this->assertStringContainsString('phpstan-deprecation-rules/rules.neon', $neon,
      'phpstan.neon must include phpstan-deprecation-rules for deprecation detection');
  }

  public function testPhpstanConfigDocumentsMglamanExtension(): void {
    $neon = file_get_contents($this->moduleRoot . '/phpstan.neon');
    $this->assertStringContainsString('mglaman/phpstan-drupal', $neon,
      'phpstan.neon must document the mglaman/phpstan-drupal extension (even if excluded in standalone mode)');
  }

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
    $content = file_get_contents($path);
    $this->assertStringContainsString('tests/', $content,
      '.gitattributes must exclude /tests/ from distribution');
    $this->assertStringContainsString('export-ignore', $content,
      '.gitattributes must use export-ignore directives');
    $this->assertStringContainsString('.github/', $content,
      '.gitattributes must exclude /.github/ from distribution');
    $this->assertStringContainsString('phpstan.neon', $content,
      '.gitattributes must exclude phpstan.neon from distribution');
  }

  // -------------------------------------------------------------------
  // No raw filesystem calls in src/ (regression guard)
  // -------------------------------------------------------------------

  /**
   * Verify no raw PHP filesystem functions exist in src/ without phpcs:ignore.
   *
   * Method calls via FileSystemInterface (->mkdir, ->delete, etc.) are allowed.
   * Only plain PHP function calls (mkdir(), unlink(), etc.) are flagged.
   *
   * Allowed exceptions:
   * - Any line with a phpcs:ignore comment (including the line above)
   * - proc_open/feof/fgets/fclose/fread (subprocess pipe operations)
   * - Method calls: ->mkdir(, ->delete(, etc.
   */
  public function testNoRawFilesystemCalls(): void {
    $srcDir = $this->moduleRoot . '/src/';
    // Raw PHP function names to check — only flag bare calls, not method calls.
    $forbidden = [
      'strip_tags(',
      'file_put_contents(',
      'unlink(',
      'rmdir(',
      'mkdir(',
      'copy(',
      'chmod(',
    ];
    // Patterns that make a line acceptable even if it matches a forbidden func.
    $allowed = ['phpcs:ignore', 'proc_open', 'feof', 'fgets', 'fclose', 'fread'];

    $violations = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      $lines = file($file->getPathname());
      foreach ($lines as $num => $line) {
        foreach ($forbidden as $func) {
          if (!str_contains($line, $func)) {
            continue;
          }
          // Skip method calls: ->mkdir(, ->copy(, etc.
          $funcName = rtrim($func, '(');
          if (preg_match('/->\\s*' . preg_quote($funcName, '/') . '\\s*\\(/', $line)) {
            continue;
          }
          // Skip static calls: FileSystemInterface::mkdir( etc.
          if (preg_match('/::' . preg_quote($funcName, '/') . '\\s*\\(/', $line)) {
            continue;
          }
          // Check this line and the previous line for phpcs:ignore.
          $context = $line . ($lines[$num - 1] ?? '');
          $isAllowed = FALSE;
          foreach ($allowed as $exception) {
            if (str_contains($context, $exception)) {
              $isAllowed = TRUE;
              break;
            }
          }
          if (!$isAllowed) {
            $violations[] = $file->getPathname() . ':' . ($num + 1) . ' — ' . trim($line);
          }
        }
      }
    }

    $this->assertEmpty($violations,
      "Raw filesystem calls found in src/ (add phpcs:ignore with explanation if intentional):\n" . implode("\n", $violations));
  }

  // -------------------------------------------------------------------
  // Release workflow vendor test directory excludes
  // -------------------------------------------------------------------

  public function test_release_workflow_prunes_vendor_test_dirs(): void {
    $workflow = file_get_contents($this->moduleRoot . '/.github/workflows/release.yml');
    $this->assertStringContainsString(
      '-name tests -o -name test',
      $workflow,
      'Release workflow must prune vendor test/ and tests/ directories from the staged archive'
    );
  }

  public function test_release_workflow_validate_zip_checks_test_singular(): void {
    $workflow = file_get_contents($this->moduleRoot . '/.github/workflows/release.yml');
    $this->assertStringContainsString(
      'scolta-drupal/vendor/.+/test/',
      $workflow,
      'validate-zip job must check for vendor test/ directories (singular)'
    );
  }

  public function test_release_workflow_has_lock_guard(): void {
    $workflow = file_get_contents($this->moduleRoot . '/.github/workflows/release.yml');
    $this->assertStringContainsString(
      'LOCK GUARD FAILED',
      $workflow,
      'Release workflow must include the scolta-php lock-source guard'
    );
  }

  public function test_release_workflow_has_disallowed_extension_guard(): void {
    $workflow = file_get_contents($this->moduleRoot . '/.github/workflows/release.yml');
    $this->assertStringContainsString(
      '.sha256',
      $workflow,
      'validate-zip must check for disallowed .sha256 files'
    );
    $this->assertStringContainsString(
      '.toml',
      $workflow,
      'validate-zip must check for disallowed .toml files'
    );
  }

}
