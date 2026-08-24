<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\scolta\Service\IndexLocator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shared index-existence resolver.
 *
 * Three call sites previously disagreed: PagefindBuilder::getStatus()
 * checked the legacy root pagefind.js, HealthController and scolta:status
 * checked pagefind/pagefind.js, and the search block checked
 * pagefind-entry.json — so the same directory could be "built" in one
 * report and "missing" in another. IndexLocator is now the single answer.
 */
class IndexLocatorTest extends TestCase {

  private string $dir;

  protected function setUp(): void {
    $this->dir = sys_get_temp_dir() . '/scolta-locator-' . uniqid();
    mkdir($this->dir, 0777, TRUE);
  }

  protected function tearDown(): void {
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->dir);
  }

  public function test_missing_index_returns_null(): void {
    $locator = new IndexLocator();
    $this->assertNull($locator->locate($this->dir));
    $this->assertFalse($locator->exists($this->dir));
  }

  public function test_modern_layout_is_located(): void {
    mkdir($this->dir . '/pagefind/fragment', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'js');
    file_put_contents($this->dir . '/pagefind/fragment/en_a.pf_fragment', 'x');
    file_put_contents($this->dir . '/pagefind/fragment/en_b.pf_fragment', 'x');

    $locator = new IndexLocator();
    $location = $locator->locate($this->dir);

    $this->assertNotNull($location);
    $this->assertSame($this->dir . '/pagefind/pagefind.js', $location['indexFile']);
    $this->assertSame(2, $locator->countFragments($location));
    $this->assertTrue($locator->exists($this->dir));
  }

  public function test_legacy_root_layout_is_located(): void {
    mkdir($this->dir . '/fragment', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind.js', 'js');
    file_put_contents($this->dir . '/fragment/en_a.pf_fragment', 'x');

    $locator = new IndexLocator();
    $location = $locator->locate($this->dir);

    $this->assertNotNull($location);
    $this->assertSame($this->dir . '/pagefind.js', $location['indexFile']);
    $this->assertSame(1, $locator->countFragments($location));
  }

  public function test_modern_layout_wins_over_legacy(): void {
    mkdir($this->dir . '/pagefind', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'modern');
    file_put_contents($this->dir . '/pagefind.js', 'legacy');

    $locator = new IndexLocator();
    $this->assertSame(
      $this->dir . '/pagefind/pagefind.js',
      $locator->locate($this->dir)['indexFile']
    );
  }

  public function test_entry_json_alone_is_not_an_index(): void {
    // The block used to treat pagefind-entry.json as the existence marker;
    // pagefind.js is the load-bearing artifact the browser requests.
    mkdir($this->dir . '/pagefind', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind-entry.json', '{}');

    $locator = new IndexLocator();
    $this->assertFalse($locator->exists($this->dir));
  }

  // -------------------------------------------------------------------
  // All four call sites resolve through the locator.
  // -------------------------------------------------------------------

  public function test_fragment_files_and_count_agree(): void {
    // fragmentFiles() owns the one glob; countFragments() is its count. The
    // health controller consumes both the list and the count, so they MUST
    // never diverge.
    mkdir($this->dir . '/pagefind/fragment', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'js');
    file_put_contents($this->dir . '/pagefind/fragment/en_a.pf_fragment', 'x');
    file_put_contents($this->dir . '/pagefind/fragment/en_b.pf_fragment', 'x');
    file_put_contents($this->dir . '/pagefind/fragment/en_c.pf_fragment', 'x');

    $locator = new IndexLocator();
    $location = $locator->locate($this->dir);

    $this->assertNotNull($location);
    $this->assertCount(3, $locator->fragmentFiles($location));
    $this->assertSame(
      $locator->countFragments($location),
      count($locator->fragmentFiles($location)),
      'countFragments() must equal count(fragmentFiles()) — one glob, two consumers'
    );
  }

  public function test_fragment_files_empty_when_no_fragments(): void {
    mkdir($this->dir . '/pagefind/fragment', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'js');

    $locator = new IndexLocator();
    $location = $locator->locate($this->dir);

    $this->assertSame([], $locator->fragmentFiles($location));
    $this->assertSame(0, $locator->countFragments($location));
  }

  public function test_health_controller_uses_fragment_files(): void {
    // The health controller's inline glob is gone — it now resolves the
    // fragment list (count plus the file it filesize()s) through the shared
    // method, so the glob pattern lives in exactly one place. Since the
    // backend/frontend split that shared method is IndexOrigin's, because the
    // controller is a frontend one and IndexLocator is scolta's.
    $src = file_get_contents(dirname(__DIR__, 2) . '/modules/scolta_ui/src/Controller/HealthController.php');
    $this->assertStringContainsString(
      '$this->indexOrigin->fragmentFiles(',
      $src,
      'HealthController must enumerate fragments through IndexOrigin::fragmentFiles()'
    );
    $this->assertStringNotContainsString(
      "glob(\$location['fragmentDir']",
      $src,
      'HealthController must not re-glob the fragment directory — that glob now lives only in the origin service'
    );
  }

  public function test_all_call_sites_use_a_resolver(): void {
    // Still one answer per module to "is there an index", rather than the four
    // disagreeing inline checks this class was written to end. There are two
    // resolvers now because there are two modules: the backend asks its own
    // filesystem through IndexLocator, and the frontend asks IndexOrigin,
    // which answers for a remote index as well as a local one. Neither module
    // reaches for the other's, which is what keeps the split intact.
    $root = dirname(__DIR__, 2);
    $sites = [
      'src/Service/PagefindBuilder.php' => 'indexLocator',
      'src/Commands/ScoltaCommands.php' => 'indexLocator',
      'modules/scolta_ui/src/Controller/HealthController.php' => 'indexOrigin',
      'modules/scolta_ui/src/Plugin/Block/ScoltaSearchBlock.php' => 'indexOrigin',
    ];
    foreach ($sites as $file => $needle) {
      $this->assertStringContainsString(
        $needle,
        file_get_contents($root . '/' . $file),
        "{$file} must resolve index existence through the shared resolver, not an inline check"
      );
    }

    foreach (['Controller/HealthController.php', 'Plugin/Block/ScoltaSearchBlock.php'] as $frontend) {
      $this->assertStringNotContainsString(
        'IndexLocator',
        file_get_contents($root . '/modules/scolta_ui/src/' . $frontend),
        "scolta_ui/src/{$frontend} must not reach for the backend's IndexLocator"
      );
    }
  }

}
