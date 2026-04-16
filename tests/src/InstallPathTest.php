<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the install → configure path on Drupal.
 *
 * Verifies that Scolta requires no FFI, Extism, or native PHP extensions
 * beyond standard PHP — the core managed hosting compatibility requirement.
 */
class InstallPathTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // Default paths use Drupal stream wrappers.
  // -------------------------------------------------------------------

  public function testDefaultPathsUseDrupalStreamWrappers(): void {
    $config = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');

    $this->assertStringStartsWith(
      'private://',
      $config['pagefind']['build_dir'] ?? '',
      'build_dir must default to private:// stream wrapper'
    );
    $this->assertStringStartsWith(
      'public://',
      $config['pagefind']['output_dir'] ?? '',
      'output_dir must default to public:// stream wrapper'
    );
  }

  // -------------------------------------------------------------------
  // No FFI/Extism dependencies anywhere in module source.
  // -------------------------------------------------------------------

  public function testModuleSourceHasNoFfiReferences(): void {
    $srcDir = $this->moduleRoot . '/src';
    $it     = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      $content = file_get_contents($file->getPathname());
      $rel     = str_replace($this->moduleRoot . '/', '', $file->getPathname());

      foreach (['ext-ffi', 'Extism', 'extism', 'extension_loaded(\'ffi\')'] as $term) {
        $this->assertStringNotContainsString(
          $term,
          $content,
          "File $rel must not reference removed component \"$term\""
        );
      }
    }
  }

  // -------------------------------------------------------------------
  // All 7 Drush commands are registered.
  // -------------------------------------------------------------------

  public function testDrushCommandsRegistered(): void {
    $commandsFile = $this->moduleRoot . '/src/Commands/ScoltaCommands.php';
    $this->assertFileExists($commandsFile);

    $source   = file_get_contents($commandsFile);
    $commands = [
      'build',
      'export',
      'rebuildIndex',
      'status',
      'clearCache',
      'downloadPagefind',
      'checkSetup',
    ];

    foreach ($commands as $cmd) {
      $this->assertStringContainsString(
        "function $cmd",
        $source,
        "Drush command \"$cmd\" must be defined in ScoltaCommands"
      );
    }
  }

  // -------------------------------------------------------------------
  // Commands do not reference FFI or Extism.
  // -------------------------------------------------------------------

  public function testDrushCommandsHaveNoFfiReferences(): void {
    $source = file_get_contents($this->moduleRoot . '/src/Commands/ScoltaCommands.php');
    $this->assertStringNotContainsString('FFI', $source);
    $this->assertStringNotContainsString('Extism', $source);
    $this->assertStringNotContainsString('ext-ffi', $source);
  }

}
