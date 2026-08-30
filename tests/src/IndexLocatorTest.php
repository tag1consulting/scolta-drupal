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

  // -------------------------------------------------------------------
  // pageCount() reads pagefind-entry.json instead of glob()ing fragments.
  // -------------------------------------------------------------------

  public function test_page_count_sums_across_languages(): void {
    mkdir($this->dir . '/pagefind', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'js');
    file_put_contents($this->dir . '/pagefind/pagefind-entry.json', json_encode([
      'languages' => [
        'en' => ['page_count' => 40],
        'de' => ['page_count' => 2],
      ],
    ]));

    $locator = new IndexLocator();
    $location = $locator->locate($this->dir);

    $this->assertNotNull($location);
    $this->assertSame(42, $locator->pageCount($location));
  }

  public function test_locator_is_wired_into_the_consuming_services(): void {
    // The locator only removes the disagreement if the services that answer
    // "is the index built?" actually receive it. HealthController and
    // ScoltaSearchBlock resolve it in their create() factories (covered by
    // their own tests); the two YAML-wired consumers are pinned here.
    $root = dirname(__DIR__, 2);

    $services = \Symfony\Component\Yaml\Yaml::parseFile($root . '/scolta.services.yml')['services'];
    $this->assertArrayHasKey('scolta.index_locator', $services);
    $this->assertContains(
      '@scolta.index_locator',
      $services['scolta.pagefind_builder']['arguments'],
      'PagefindBuilder must be handed the shared locator'
    );

    $drush = \Symfony\Component\Yaml\Yaml::parseFile($root . '/drush.services.yml')['services'];
    $commandArgs = array_merge(...array_map(
      static fn (array $definition) => $definition['arguments'] ?? [],
      array_values($drush)
    ));
    $this->assertContains(
      '@scolta.index_locator',
      $commandArgs,
      'The drush commands must be handed the shared locator'
    );
  }

}
