<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the scolta-core → scolta-php rename is fully propagated.
 *
 * Scans all source files for stale references to old package names.
 * This test catches any reference that was missed during the rename.
 */
class RenameIntegrityTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  /**
   * Get all tracked source files (excluding vendor, .git, node_modules).
   */
  private function getAllSourceFiles(): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->moduleRoot, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::LEAVES_ONLY
    );

    $excludeDirs = ['vendor', '.git', 'node_modules', '.phpunit.cache', 'tests'];

    foreach ($iterator as $file) {
      $path = $file->getPathname();

      // Skip excluded directories.
      $skip = false;
      foreach ($excludeDirs as $dir) {
        if (str_contains($path, '/' . $dir . '/')) {
          $skip = true;
          break;
        }
      }
      if ($skip) continue;

      $ext = $file->getExtension();
      if (in_array($ext, ['php', 'yml', 'yaml', 'json', 'js', 'css', 'md', 'txt'], true)) {
        $files[] = $path;
      }
    }

    return $files;
  }

  public function testNoReferencesToScoltaCoreWasm(): void {
    $stale = [];
    foreach ($this->getAllSourceFiles() as $file) {
      $contents = file_get_contents($file);
      if (preg_match('/scolta[-_]core[-_]wasm/i', $contents)) {
        $stale[] = str_replace($this->moduleRoot . '/', '', $file);
      }
    }
    $this->assertEmpty($stale,
      "Files still reference scolta-core-wasm:\n" . implode("\n", $stale));
  }

  public function testNoReferencesToOldComposerPackageName(): void {
    $stale = [];
    foreach ($this->getAllSourceFiles() as $file) {
      $contents = file_get_contents($file);
      // Match "tag1/scolta" but NOT "tag1/scolta-php", "tag1/scolta-drupal", etc.
      if (preg_match('/"tag1\/scolta"/', $contents)) {
        $stale[] = str_replace($this->moduleRoot . '/', '', $file);
      }
    }
    $this->assertEmpty($stale,
      "Files still reference old package name \"tag1/scolta\":\n" . implode("\n", $stale));
  }

  public function testNoOldVendorPaths(): void {
    $stale = [];
    foreach ($this->getAllSourceFiles() as $file) {
      $contents = file_get_contents($file);
      // Match vendor/tag1/scolta/ but NOT vendor/tag1/scolta-php/ or vendor/tag1/scolta-drupal/
      if (preg_match('/vendor\/tag1\/scolta\//', $contents)) {
        $stale[] = str_replace($this->moduleRoot . '/', '', $file);
      }
    }
    $this->assertEmpty($stale,
      "Files still reference old vendor path vendor/tag1/scolta/:\n" . implode("\n", $stale));
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

  public function testLibrariesYmlDoesNotReferenceOldPackageName(): void {
    $content = file_get_contents($this->moduleRoot . '/scolta.libraries.yml');

    // Must not reference the old vendor path (vendor/tag1/scolta/).
    $this->assertStringNotContainsString('vendor/tag1/scolta/', $content,
      "libraries.yml should not reference old vendor/tag1/scolta/ path");

    // If vendor paths are used, they should reference scolta-php.
    if (str_contains($content, 'vendor/tag1/')) {
      $this->assertStringContainsString('tag1/scolta-php', $content,
        "libraries.yml vendor references should use tag1/scolta-php");
    }

    // Comments mentioning the shared package should say scolta-php.
    if (preg_match('/scolta-(?:core|php)/', $content)) {
      $this->assertStringNotContainsString('scolta-core', $content,
        "libraries.yml should reference scolta-php, not scolta-core");
    }
  }

  /**
   * Verify that comments referencing the shared PHP library say "scolta-php"
   * (not "scolta-core", which now means the Rust crate).
   */
  public function testPhpCommentsReferenceScoltaPhpNotScoltaCore(): void {
    $issues = [];

    foreach ($this->getAllSourceFiles() as $file) {
      if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
        continue;
      }

      $contents = file_get_contents($file);

      // Look for "scolta-core" in comments only.
      // We extract single-line comments (// ...) and block comments (/* ... */).
      preg_match_all('/\/\/[^\n]*|\/\*.*?\*\//s', $contents, $comments);

      foreach ($comments[0] as $comment) {
        // "scolta-core" in a comment is suspicious — it should say "scolta-php"
        // when referring to the PHP package. But "scolta-core" is valid when
        // referring to the Rust WASM crate, so only flag it if the surrounding
        // context suggests it means the PHP package.
        if (preg_match('/scolta-core(?!.*(?:WASM|Rust|crate|wasm))/i', $comment)) {
          $short = str_replace($this->moduleRoot . '/', '', $file);
          $snippet = trim(substr($comment, 0, 120));
          $issues[] = "{$short}: {$snippet}";
        }
      }
    }

    $this->assertEmpty($issues,
      "PHP comments may reference 'scolta-core' when 'scolta-php' is meant:\n"
      . implode("\n", $issues)
    );
  }

  /**
   * Verify scolta.js is present and accessible.
   */
  public function testScoltaJsExists(): void {
    $jsFile = $this->moduleRoot . '/js/scolta.js';
    $this->assertFileExists($jsFile,
      'scolta.js must exist at js/scolta.js (symlink or copy from scolta-php)');
    $contents = file_get_contents($jsFile);
    $this->assertNotEmpty($contents, 'scolta.js must not be empty');
    $this->assertStringContainsString('Scolta', $contents,
      'scolta.js must contain the Scolta namespace');
  }

  /**
   * Verify scolta.css is present and accessible.
   */
  public function testScoltaCssExists(): void {
    $cssFile = $this->moduleRoot . '/css/scolta.css';
    $this->assertFileExists($cssFile,
      'scolta.css must exist at css/scolta.css (symlink or copy from scolta-php)');
    $contents = file_get_contents($cssFile);
    $this->assertNotEmpty($contents);
    $this->assertStringContainsString('scolta-', $contents);
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
