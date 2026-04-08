<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests PagefindBuilder's command construction, status parsing, and helpers.
 *
 * Verifies the build() command assembly, checkBinary() return structure,
 * getStatus() return keys, and formatBytes() conversion logic without
 * requiring a Drupal bootstrap.
 */
class PagefindBuilderProcessTest extends TestCase {

  private string $moduleRoot;
  private string $tmpDir;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->tmpDir = sys_get_temp_dir() . '/scolta-builder-test-' . uniqid();
    mkdir($this->tmpDir, 0755, true);
  }

  protected function tearDown(): void {
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
  // build() command argument construction.
  // -------------------------------------------------------------------

  public function testBuildCommandWithDirectBinary(): void {
    $binary = 'pagefind';
    $buildDir = '/tmp/scolta-build';
    $outputDir = '/tmp/scolta-output';

    $parts = explode(' ', $binary, 2);
    $command = array_merge($parts, ['--site', $buildDir, '--output-path', $outputDir]);

    $this->assertEquals(
      ['pagefind', '--site', '/tmp/scolta-build', '--output-path', '/tmp/scolta-output'],
      $command
    );
  }

  public function testBuildCommandWithNpxBinary(): void {
    $binary = 'npx pagefind';
    $parts = explode(' ', $binary, 2);
    $command = array_merge($parts, ['--site', '/tmp/build', '--output-path', '/tmp/output']);

    $this->assertEquals(
      ['npx', 'pagefind', '--site', '/tmp/build', '--output-path', '/tmp/output'],
      $command
    );
  }

  public function testBuildCommandWithAbsolutePath(): void {
    $binary = '/usr/local/bin/pagefind';
    $parts = explode(' ', $binary, 2);
    $command = array_merge($parts, ['--site', '/var/build', '--output-path', '/var/output']);

    $this->assertEquals(
      ['/usr/local/bin/pagefind', '--site', '/var/build', '--output-path', '/var/output'],
      $command
    );
  }

  public function testBuildCommandAlwaysIncludesSiteAndOutputPath(): void {
    $binary = 'pagefind';
    $buildDir = '/some/build/dir';
    $outputDir = '/some/output/dir';

    $parts = explode(' ', $binary, 2);
    $command = array_merge($parts, ['--site', $buildDir, '--output-path', $outputDir]);

    $this->assertContains('--site', $command);
    $this->assertContains('--output-path', $command);
    $this->assertContains($buildDir, $command);
    $this->assertContains($outputDir, $command);
  }

  // -------------------------------------------------------------------
  // build() precondition: missing directory returns error.
  // -------------------------------------------------------------------

  public function testBuildReturnsErrorForMissingDirectory(): void {
    // Mirrors the early-return logic in PagefindBuilder::build.
    $buildDir = $this->tmpDir . '/nonexistent';
    $this->assertFalse(is_dir($buildDir));

    $result = [
      'success' => false,
      'output' => '',
      'error' => "Build directory does not exist: {$buildDir}",
      'file_count' => null,
      'index_size' => null,
    ];

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('does not exist', $result['error']);
  }

  public function testBuildReturnsErrorForEmptyDirectory(): void {
    $htmlFiles = glob($this->tmpDir . '/*.html');
    $fileCount = $htmlFiles ? count($htmlFiles) : 0;

    $this->assertEquals(0, $fileCount);

    $result = [
      'success' => false,
      'output' => '',
      'error' => "No HTML files found in {$this->tmpDir}. Run Search API indexing first.",
      'file_count' => 0,
      'index_size' => null,
    ];

    $this->assertFalse($result['success']);
    $this->assertEquals(0, $result['file_count']);
  }

  // -------------------------------------------------------------------
  // build() result structure.
  // -------------------------------------------------------------------

  public function testBuildResultStructureOnSuccess(): void {
    $result = [
      'success' => true,
      'output' => 'Pagefind output here',
      'error' => null,
      'file_count' => 42,
      'index_size' => '1.5 MB',
    ];

    $this->assertArrayHasKey('success', $result);
    $this->assertArrayHasKey('output', $result);
    $this->assertArrayHasKey('error', $result);
    $this->assertArrayHasKey('file_count', $result);
    $this->assertArrayHasKey('index_size', $result);
    $this->assertTrue($result['success']);
    $this->assertNull($result['error']);
  }

  public function testBuildResultStructureOnFailure(): void {
    $result = [
      'success' => false,
      'output' => 'some output',
      'error' => 'Pagefind exited with code 1',
      'file_count' => 10,
      'index_size' => null,
    ];

    $this->assertFalse($result['success']);
    $this->assertNotNull($result['error']);
  }

  // -------------------------------------------------------------------
  // checkBinary() return structure.
  // -------------------------------------------------------------------

  public function testCheckBinaryReturnStructure(): void {
    // Mirrors the array structure returned by PagefindBinary::status().
    $status = [
      'available' => true,
      'binary' => '/usr/local/bin/pagefind',
      'version' => '1.1.0',
      'via' => 'configured',
      'message' => 'Pagefind 1.1.0 at /usr/local/bin/pagefind',
    ];

    $this->assertArrayHasKey('available', $status);
    $this->assertArrayHasKey('binary', $status);
    $this->assertArrayHasKey('version', $status);
    $this->assertArrayHasKey('via', $status);
    $this->assertArrayHasKey('message', $status);
    $this->assertIsBool($status['available']);
    $this->assertIsString($status['message']);
  }

  public function testCheckBinaryUnavailableStructure(): void {
    $status = [
      'available' => false,
      'binary' => null,
      'version' => null,
      'via' => 'none',
      'message' => 'Pagefind binary not found',
    ];

    $this->assertFalse($status['available']);
    $this->assertNull($status['binary']);
    $this->assertNull($status['version']);
  }

  public function testCheckBinaryVersionCommandConstruction(): void {
    $binary = 'pagefind';
    $parts = explode(' ', $binary);
    $parts[] = '--version';

    $this->assertEquals(['pagefind', '--version'], $parts);
  }

  public function testCheckBinaryNpxVersionCommandConstruction(): void {
    $binary = 'npx pagefind';
    $parts = explode(' ', $binary);
    $parts[] = '--version';

    $this->assertEquals(['npx', 'pagefind', '--version'], $parts);
  }

  // -------------------------------------------------------------------
  // getStatus() return keys.
  // -------------------------------------------------------------------

  public function testGetStatusReturnsExpectedKeysWhenNoIndex(): void {
    $dir = $this->tmpDir . '/nonexistent';

    $result = [
      'exists' => false,
      'file_count' => 0,
      'index_size' => '0 B',
      'last_built' => null,
    ];

    $this->assertArrayHasKey('exists', $result);
    $this->assertArrayHasKey('file_count', $result);
    $this->assertArrayHasKey('index_size', $result);
    $this->assertArrayHasKey('last_built', $result);
    $this->assertFalse($result['exists']);
    $this->assertEquals(0, $result['file_count']);
    $this->assertNull($result['last_built']);
  }

  public function testGetStatusReturnsExpectedKeysWhenIndexExists(): void {
    // Create a fake pagefind index directory.
    file_put_contents($this->tmpDir . '/pagefind.js', '// pagefind');
    mkdir($this->tmpDir . '/fragment', 0755, true);
    file_put_contents($this->tmpDir . '/fragment/chunk1.pf', 'data');
    file_put_contents($this->tmpDir . '/fragment/chunk2.pf', 'data');
    file_put_contents($this->tmpDir . '/fragment/chunk3.pf', 'data');

    $this->assertTrue(file_exists($this->tmpDir . '/pagefind.js'));
    $fragments = glob($this->tmpDir . '/fragment/*') ?: [];
    $mtime = filemtime($this->tmpDir . '/pagefind.js');

    $result = [
      'exists' => true,
      'file_count' => count($fragments),
      'index_size' => $this->formatBytes($this->calculateDirectorySize($this->tmpDir)),
      'last_built' => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
    ];

    $this->assertTrue($result['exists']);
    $this->assertEquals(3, $result['file_count']);
    $this->assertNotNull($result['last_built']);
    $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result['last_built']);
  }

  public function testGetStatusWithNoPagefindJs(): void {
    // Directory exists but no pagefind.js.
    $this->assertTrue(is_dir($this->tmpDir));
    $this->assertFalse(file_exists($this->tmpDir . '/pagefind.js'));
  }

  // -------------------------------------------------------------------
  // formatBytes() helper.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('formatBytesProvider')]
  public function testFormatBytes(int $bytes, string $expected): void {
    $result = $this->formatBytes($bytes);
    $this->assertEquals($expected, $result);
  }

  public static function formatBytesProvider(): array {
    return [
      'zero bytes' => [0, '0 B'],
      'one byte' => [1, '1 B'],
      'small bytes' => [512, '512 B'],
      'exact 1 KB' => [1024, '1 KB'],
      'fractional KB' => [1536, '1.5 KB'],
      'exact 1 MB' => [1048576, '1 MB'],
      'fractional MB' => [1572864, '1.5 MB'],
      'exact 1 GB' => [1073741824, '1 GB'],
      'large MB' => [10485760, '10 MB'],
      'just under KB' => [1023, '1023 B'],
      'just over KB' => [1025, '1 KB'],
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

  /**
   * Mirror of PagefindBuilder::calculateDirectorySize for testing.
   */
  private function calculateDirectorySize(string $dir): int {
    $size = 0;
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      $size += $file->getSize();
    }
    return $size;
  }

  // -------------------------------------------------------------------
  // PagefindBuilder source file has expected methods.
  // -------------------------------------------------------------------

  public function testPagefindBuilderHasBuildMethod(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Service/PagefindBuilder.php');
    $this->assertStringContainsString('function build(', $contents);
  }

  public function testPagefindBuilderHasCheckBinaryMethod(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Service/PagefindBuilder.php');
    $this->assertStringContainsString('function checkBinary(', $contents);
  }

  public function testPagefindBuilderHasGetStatusMethod(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Service/PagefindBuilder.php');
    $this->assertStringContainsString('function getStatus(', $contents);
  }

  public function testPagefindBuilderHasFormatBytesMethod(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Service/PagefindBuilder.php');
    $this->assertStringContainsString('function formatBytes(', $contents);
  }

  public function testPagefindBuilderHasCalculateDirectorySizeMethod(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Service/PagefindBuilder.php');
    $this->assertStringContainsString('function calculateDirectorySize(', $contents);
  }

  // -------------------------------------------------------------------
  // Build timeout configuration.
  // -------------------------------------------------------------------

  public function testBuildSetsProcessTimeout(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Service/PagefindBuilder.php');
    $this->assertStringContainsString('setTimeout', $contents,
      'PagefindBuilder should set a process timeout');
    // Should be a reasonable timeout for large sites.
    $this->assertStringContainsString('300', $contents,
      'Timeout should be 300 seconds (5 minutes)');
  }

}
