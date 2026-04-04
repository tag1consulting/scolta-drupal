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
  // All PHP files have correct namespace declarations.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('phpFileProvider')]
  public function testPhpFileNamespaceMatchesPath(string $file): void {
    $contents = file_get_contents($file);

    if (preg_match('/^namespace\s+(.+);/m', $contents, $m)) {
      $namespace = $m[1];

      // Derive expected namespace from file path relative to src/.
      $relative = str_replace($this->moduleRoot . '/src/', '', $file);
      $dir = dirname($relative);
      $expectedNamespace = 'Drupal\\scolta';
      if ($dir !== '.') {
        $expectedNamespace .= '\\' . str_replace('/', '\\', $dir);
      }

      $this->assertEquals(
        $expectedNamespace, $namespace,
        "Namespace mismatch in {$file}"
      );
    }
  }

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

  // -------------------------------------------------------------------
  // All PHP files pass syntax check.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('phpFileProvider')]
  public function testPhpSyntaxIsValid(string $file): void {
    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
    $this->assertEquals(0, $exitCode,
      "Syntax error in {$file}: " . implode("\n", $output));
  }

  // -------------------------------------------------------------------
  // PHP use-statements reference classes that exist in scolta-php or Drupal.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('phpFileProvider')]
  public function testScoltaPhpImportsReferenceRealClasses(string $file): void {
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
      $expectedFile = $this->moduleRoot . '/../scolta-php/src/' . $relative . '.php';

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

  private function classToFile(string $fqcn): string {
    // Drupal\scolta\Foo\Bar -> src/Foo/Bar.php
    $fqcn = ltrim($fqcn, '\\');
    $relative = str_replace('\\', '/', str_replace('Drupal\\scolta\\', '', $fqcn));
    return $this->moduleRoot . '/src/' . $relative . '.php';
  }

}
