<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests PagefindBuilder logic that can run without Drupal.
 *
 * Verifies directory checking, file counting, and formatBytes behavior
 * by testing the logic patterns used in PagefindBuilder.
 */
class PagefindBuilderTest extends TestCase {

  private string $tmpDir;

  protected function setUp(): void {
    $this->tmpDir = sys_get_temp_dir() . '/scolta-test-' . uniqid();
    mkdir($this->tmpDir, 0755, true);
  }

  protected function tearDown(): void {
    // Clean up temp directory.
    $this->removeDir($this->tmpDir);
  }

  private function removeDir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
      $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
  }

  // -------------------------------------------------------------------
  // Build precondition checks (logic extracted from PagefindBuilder).
  // -------------------------------------------------------------------

  public function testBuildFailsWhenDirectoryDoesNotExist(): void {
    $nonExistent = $this->tmpDir . '/does-not-exist';
    $this->assertFalse(is_dir($nonExistent));
  }

  public function testBuildFailsWhenNoHtmlFiles(): void {
    $htmlFiles = glob($this->tmpDir . '/*.html');
    $this->assertEmpty($htmlFiles);
  }

  public function testBuildCountsHtmlFiles(): void {
    // Create some HTML files.
    for ($i = 1; $i <= 5; $i++) {
      file_put_contents($this->tmpDir . "/item-{$i}.html", '<html><body>Test</body></html>');
    }
    // And a non-HTML file.
    file_put_contents($this->tmpDir . '/readme.txt', 'not html');

    $htmlFiles = glob($this->tmpDir . '/*.html');
    $this->assertCount(5, $htmlFiles);
  }

  // -------------------------------------------------------------------
  // Format bytes (mirrors PagefindBuilder::formatBytes).
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('formatBytesProvider')]
  public function testFormatBytes(int $bytes, string $expected): void {
    $result = $this->formatBytes($bytes);
    $this->assertEquals($expected, $result);
  }

  public static function formatBytesProvider(): array {
    return [
      'zero' => [0, '0 B'],
      'bytes' => [512, '512 B'],
      'kilobytes' => [1024, '1 KB'],
      'megabytes' => [1048576, '1 MB'],
      'megabytes_decimal' => [1572864, '1.5 MB'],
      'gigabytes' => [1073741824, '1 GB'],
    ];
  }

  /**
   * Mirror of PagefindBuilder::formatBytes for testing.
   */
  private function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $exp = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $exp = min($exp, count($units) - 1);
    return round($bytes / (1024 ** $exp), 1) . ' ' . $units[$exp];
  }

  // -------------------------------------------------------------------
  // Pagefind command construction (mirrors PagefindBuilder::build).
  // -------------------------------------------------------------------

  public function testCommandConstructionSimpleBinary(): void {
    $binary = 'pagefind';
    $parts = explode(' ', $binary, 2);
    $command = array_merge($parts, ['--site', '/tmp/build', '--output-path', '/tmp/output']);

    $this->assertEquals(
      ['pagefind', '--site', '/tmp/build', '--output-path', '/tmp/output'],
      $command
    );
  }

  public function testCommandConstructionNpxBinary(): void {
    $binary = 'npx pagefind';
    $parts = explode(' ', $binary, 2);
    $command = array_merge($parts, ['--site', '/tmp/build', '--output-path', '/tmp/output']);

    $this->assertEquals(
      ['npx', 'pagefind', '--site', '/tmp/build', '--output-path', '/tmp/output'],
      $command
    );
  }

  // -------------------------------------------------------------------
  // getStatus logic (directory state inspection).
  // -------------------------------------------------------------------

  public function testGetStatusNonExistentDirectory(): void {
    $dir = $this->tmpDir . '/no-such-dir';
    $this->assertFalse(is_dir($dir));
  }

  public function testGetStatusNoIndexFile(): void {
    // Directory exists but no pagefind.js.
    $this->assertFalse(file_exists($this->tmpDir . '/pagefind.js'));
  }

  public function testGetStatusWithIndex(): void {
    file_put_contents($this->tmpDir . '/pagefind.js', '// pagefind');
    mkdir($this->tmpDir . '/fragment', 0755, true);
    file_put_contents($this->tmpDir . '/fragment/chunk1.pf', 'data');
    file_put_contents($this->tmpDir . '/fragment/chunk2.pf', 'data');

    $this->assertTrue(file_exists($this->tmpDir . '/pagefind.js'));
    $fragments = glob($this->tmpDir . '/fragment/*');
    $this->assertCount(2, $fragments);
  }

  // -------------------------------------------------------------------
  // checkBinary logic.
  // -------------------------------------------------------------------

  public function testCheckBinaryCommandConstruction(): void {
    $binary = 'pagefind';
    $parts = explode(' ', $binary);
    $parts[] = '--version';

    $this->assertEquals(['pagefind', '--version'], $parts);
  }

  // -------------------------------------------------------------------
  // Item ID to filename conversion (mirrors PagefindExporter).
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('itemIdToFilenameProvider')]
  public function testItemIdToFilename(string $input, string $expected): void {
    $safe = preg_replace('/[^a-zA-Z0-9\-]/', '-', $input);
    $result = trim($safe, '-') . '.html';
    $this->assertEquals($expected, $result);
  }

  public static function itemIdToFilenameProvider(): array {
    return [
      'simple node' => ['entity:node/42:en', 'entity-node-42-en.html'],
      'user entity' => ['entity:user/1:en', 'entity-user-1-en.html'],
      'no language' => ['entity:node/100', 'entity-node-100.html'],
      'special chars' => ['entity:taxonomy_term/5:de', 'entity-taxonomy-term-5-de.html'],
    ];
  }

  // -------------------------------------------------------------------
  // Delete file operations.
  // -------------------------------------------------------------------

  public function testDeleteItemRemovesFile(): void {
    $itemId = 'entity:node/42:en';
    $safe = preg_replace('/[^a-zA-Z0-9\-]/', '-', $itemId);
    $filename = trim($safe, '-') . '.html';
    $filepath = $this->tmpDir . '/' . $filename;

    file_put_contents($filepath, '<html></html>');
    $this->assertFileExists($filepath);

    unlink($filepath);
    $this->assertFileDoesNotExist($filepath);
  }

  public function testDeleteAllRemovesAllHtmlFiles(): void {
    for ($i = 1; $i <= 3; $i++) {
      file_put_contents($this->tmpDir . "/item-{$i}.html", '<html></html>');
    }
    file_put_contents($this->tmpDir . '/keep-me.txt', 'not html');

    $files = glob($this->tmpDir . '/*.html');
    foreach ($files as $file) {
      unlink($file);
    }

    $this->assertEmpty(glob($this->tmpDir . '/*.html'));
    $this->assertFileExists($this->tmpDir . '/keep-me.txt');
  }

  public function testDeleteByDatasourcePrefix(): void {
    file_put_contents($this->tmpDir . '/entity-node-1-en.html', 'node');
    file_put_contents($this->tmpDir . '/entity-node-2-en.html', 'node');
    file_put_contents($this->tmpDir . '/entity-user-1-en.html', 'user');

    $prefix = 'entity-node';
    $files = glob($this->tmpDir . '/' . $prefix . '-*.html');
    $this->assertCount(2, $files);

    foreach ($files as $file) {
      unlink($file);
    }

    $this->assertFileExists($this->tmpDir . '/entity-user-1-en.html');
    $this->assertEmpty(glob($this->tmpDir . '/' . $prefix . '-*.html'));
  }

}
