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
    $config = PackageManifest::settings();

    $this->assertStringStartsWith(
      'public://',
      $config['pagefind']['build_dir'] ?? '',
      'build_dir must default to public:// stream wrapper for out-of-box compatibility'
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
    foreach (PackageManifest::sourceFiles() as $rel => $content) {

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
    // Two command classes since the backend/frontend split: the build
    // pipeline in scolta, the AI commands in scolta_ui.
    $classes = [
      'ScoltaCommands' => $this->moduleRoot . '/src/Commands/ScoltaCommands.php',
      'ScoltaUiCommands' => $this->moduleRoot . '/modules/scolta_ui/src/Commands/ScoltaUiCommands.php',
    ];
    $source = '';
    foreach ($classes as $name => $file) {
      $this->assertFileExists($file, "{$name} must exist");
      $source .= file_get_contents($file) . "\n";
    }

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
        "Drush command \"$cmd\" must be defined in one of the command classes"
      );
    }
  }

  // -------------------------------------------------------------------
  // Commands do not reference FFI or Extism.
  // -------------------------------------------------------------------

  public function testDrushCommandsHaveNoFfiReferences(): void {
    $source = file_get_contents($this->moduleRoot . '/src/Commands/ScoltaCommands.php')
      . file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Commands/ScoltaUiCommands.php');
    $this->assertStringNotContainsString('FFI', $source);
    $this->assertStringNotContainsString('Extism', $source);
    $this->assertStringNotContainsString('ext-ffi', $source);
  }

}
