<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\File\FileSystemInterface;
use Drupal\scolta\Service\IndexLocator;
use Drupal\scolta\Service\PagefindBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Behavioral tests for the real PagefindBuilder.
 *
 * The builder is constructed with stubbed services and a real IndexLocator,
 * and every assertion is on a return value or a filesystem effect. Anything
 * that would actually run the pagefind binary is out of unit territory and
 * is not exercised here.
 */
class PagefindBuilderTest extends TestCase {

  private string $tmpDir;

  protected function setUp(): void {
    $this->tmpDir = sys_get_temp_dir() . '/scolta-test-' . uniqid();
    mkdir($this->tmpDir, 0755, TRUE);
  }

  protected function tearDown(): void {
    $this->removeDir($this->tmpDir);
  }

  private function removeDir(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
      $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
  }

  /**
   * Builds the real service with stubbed logger/filesystem.
   */
  private function createBuilder(): PagefindBuilder {
    return new PagefindBuilder(
      new NullLogger(),
      $this->createStub(FileSystemInterface::class),
      new IndexLocator()
    );
  }

  // -------------------------------------------------------------------
  // build() preconditions.
  // -------------------------------------------------------------------

  public function testBuildFailsWhenDirectoryDoesNotExist(): void {
    $nonExistent = $this->tmpDir . '/does-not-exist';

    $result = $this->createBuilder()->build('pagefind', $nonExistent, $this->tmpDir . '/out');

    $this->assertFalse($result['success']);
    $this->assertSame("Build directory does not exist: {$nonExistent}", $result['error']);
    $this->assertNull($result['file_count']);
    $this->assertNull($result['index_size']);
  }

  public function testBuildFailsWhenNoHtmlFiles(): void {
    // Directory exists but holds no .html files.
    file_put_contents($this->tmpDir . '/readme.txt', 'not html');

    $result = $this->createBuilder()->build('pagefind', $this->tmpDir, $this->tmpDir . '/out');

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('No HTML files found', $result['error']);
    $this->assertSame(0, $result['file_count']);
  }

  public function testBuildFailsWhenOutputDirectoryCannotBeCreated(): void {
    // One HTML file so the build gets past the corpus check; the stubbed
    // FileSystemInterface::mkdir() returns a falsy default, so creating the
    // missing output directory fails.
    file_put_contents($this->tmpDir . '/item.html', '<html><body>x</body></html>');
    $outputDir = $this->tmpDir . '/no-such-output';

    $result = $this->createBuilder()->build('pagefind', $this->tmpDir, $outputDir);

    $this->assertFalse($result['success']);
    $this->assertSame("Failed to create output directory: {$outputDir}", $result['error']);
    $this->assertSame(1, $result['file_count']);
  }

  public function testBuildRejectsUnknownBinary(): void {
    // Counting is recursive: nested layout plus a flat file, and a non-HTML
    // file that must not be counted.
    mkdir($this->tmpDir . '/node/42', 0755, TRUE);
    file_put_contents($this->tmpDir . '/node/42/index.html', '<html><body>a</body></html>');
    file_put_contents($this->tmpDir . '/flat.html', '<html><body>b</body></html>');
    file_put_contents($this->tmpDir . '/notes.txt', 'not html');
    $outputDir = $this->tmpDir . '/out';
    mkdir($outputDir, 0755, TRUE);

    $result = $this->createBuilder()->build('/usr/bin/rm -rf', $this->tmpDir, $outputDir);

    $this->assertFalse($result['success']);
    $this->assertSame('Invalid Pagefind binary path', $result['error']);
    $this->assertSame(2, $result['file_count']);
  }

  // -------------------------------------------------------------------
  // getStatus() against real index fixtures.
  // -------------------------------------------------------------------

  public function testGetStatusNonExistentDirectory(): void {
    $status = $this->createBuilder()->getStatus($this->tmpDir . '/no-such-dir');

    $this->assertSame(
      ['exists' => FALSE, 'file_count' => 0, 'last_built' => NULL],
      $status
    );
  }

  public function testGetStatusEmptyDirectory(): void {
    $status = $this->createBuilder()->getStatus($this->tmpDir);

    $this->assertFalse($status['exists']);
    $this->assertSame(0, $status['file_count']);
    $this->assertNull($status['last_built']);
  }

  /**
   * file_count reads pagefind-entry.json's page_count, not a fragment glob.
   *
   * On a corpus with a six-figure fragment count on NFS, counting fragment
   * files by glob() is minutes-slow — getStatus() runs on every settings-form
   * GET, so it must read the count Pagefind already wrote instead.
   */
  public function testGetStatusWithIndex(): void {
    mkdir($this->tmpDir . '/pagefind/fragment', 0755, TRUE);
    file_put_contents($this->tmpDir . '/pagefind/pagefind.js', '// pagefind');
    file_put_contents($this->tmpDir . '/pagefind/fragment/en_a.pf_fragment', 'data');
    file_put_contents($this->tmpDir . '/pagefind/fragment/en_b.pf_fragment', 'data');
    file_put_contents($this->tmpDir . '/pagefind/pagefind-entry.json', json_encode([
      'languages' => ['en' => ['page_count' => 2]],
    ]));

    $status = $this->createBuilder()->getStatus($this->tmpDir);

    $this->assertTrue($status['exists']);
    $this->assertSame(2, $status['file_count']);
    $this->assertMatchesRegularExpression(
      '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
      $status['last_built'],
      'last_built must be the index file mtime formatted as Y-m-d H:i:s'
    );
  }

  /**
   * file_count falls back to the fragment glob when pagefind-entry.json is
   * missing or unreadable -- an index built by a Pagefind version, or a
   * broken build, that never wrote the entry file.
   */
  public function testGetStatusFallsBackToFragmentGlobWithoutEntryJson(): void {
    mkdir($this->tmpDir . '/pagefind/fragment', 0755, TRUE);
    file_put_contents($this->tmpDir . '/pagefind/pagefind.js', '// pagefind');
    file_put_contents($this->tmpDir . '/pagefind/fragment/en_a.pf_fragment', 'data');
    file_put_contents($this->tmpDir . '/pagefind/fragment/en_b.pf_fragment', 'data');
    file_put_contents($this->tmpDir . '/pagefind/fragment/en_c.pf_fragment', 'data');

    $status = $this->createBuilder()->getStatus($this->tmpDir);

    $this->assertSame(3, $status['file_count']);
  }

  // -------------------------------------------------------------------
  // formatBytes() — the real protected method via reflection.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('formatBytesProvider')]
  public function testFormatBytes(int $bytes, string $expected): void {
    $method = new \ReflectionMethod(PagefindBuilder::class, 'formatBytes');

    $this->assertSame($expected, $method->invoke($this->createBuilder(), $bytes));
  }

  public static function formatBytesProvider(): array {
    return [
      'zero' => [0, '0 B'],
      'one byte' => [1, '1 B'],
      'bytes' => [512, '512 B'],
      'just under a kilobyte' => [1023, '1023 B'],
      'kilobytes' => [1024, '1 KB'],
      'kilobytes_decimal' => [1536, '1.5 KB'],
      'megabytes' => [1048576, '1 MB'],
      'megabytes_decimal' => [1572864, '1.5 MB'],
      'gigabytes' => [1073741824, '1 GB'],
      'gigabytes_decimal' => [1610612736, '1.5 GB'],
      'terabytes clamp to GB' => [2199023255552, '2048 GB'],
    ];
  }

  // -------------------------------------------------------------------
  // checkBinary().
  // -------------------------------------------------------------------

  public function testCheckBinaryWithNonexistentPath(): void {
    $result = $this->createBuilder()->checkBinary('/nonexistent/path/to/pagefind');

    $this->assertSame(
      ['available', 'binary', 'version', 'via', 'message'],
      array_keys($result)
    );

    if ($result['available']) {
      // The resolver legitimately falls back to npx/PATH; on a host that has
      // pagefind installed the configured-path failure is not observable.
      $this->markTestSkipped('pagefind is installed on this host; fallback resolution succeeds.');
    }

    $this->assertNull($result['binary']);
    $this->assertNull($result['version']);
    $this->assertSame('none', $result['via']);
    $this->assertStringContainsString('/nonexistent/path/to/pagefind', $result['message']);
  }

}
