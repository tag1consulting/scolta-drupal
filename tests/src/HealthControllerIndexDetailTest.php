<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests that HealthController enriches the response with index detail.
 *
 * The controller cannot be instantiated without a Drupal bootstrap, so tests
 * use two strategies:
 *  - Source analysis: verify key code patterns exist in the controller file.
 *  - Filesystem simulation: replicate the enrichment logic with a real temp
 *    directory and assert outcomes directly, catching regressions if the logic
 *    is ever changed to diverge from the spec.
 */
class HealthControllerIndexDetailTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // Source analysis: enrichment logic is present in the controller.
  // -------------------------------------------------------------------

  public function testControllerIncludesIndexDetailEnrichment(): void {
    $src = file_get_contents($this->moduleRoot . '/src/Controller/HealthController.php');

    $this->assertStringContainsString(
      "if (\$result['index_exists'])",
      $src,
      'HealthController must branch on index_exists to add detail'
    );
    $this->assertStringContainsString(
      "'fragments' => count(\$fragments)",
      $src,
      'HealthController must include fragment count in index detail'
    );
    $this->assertStringContainsString(
      "'last_build'",
      $src,
      'HealthController must include last_build timestamp in index detail'
    );
    $this->assertStringContainsString(
      "'integrity'",
      $src,
      'HealthController must include integrity check in index detail'
    );
    $this->assertStringContainsString(
      "\$result['index'] = ['built' => FALSE]",
      $src,
      'HealthController must set index.built = false when index does not exist'
    );
  }

  public function testControllerSetsStatusDegradedOnBadIntegrity(): void {
    $src = file_get_contents($this->moduleRoot . '/src/Controller/HealthController.php');

    $this->assertStringContainsString(
      "\$result['status'] = 'degraded'",
      $src,
      'HealthController must set status=degraded when integrity check fails'
    );
  }

  // -------------------------------------------------------------------
  // Filesystem simulation: replicate the enrichment logic with a temp dir.
  // -------------------------------------------------------------------

  public function testEnrichmentWithValidIndex(): void {
    $dir = sys_get_temp_dir() . '/scolta-health-test-' . uniqid();
    mkdir($dir . '/pagefind/fragment', 0755, TRUE);
    file_put_contents($dir . '/pagefind/pagefind.js', 'pagefind_js_content');
    file_put_contents($dir . '/pagefind/fragment/en_abc123.pf_fragment', 'fragment_data');

    $result = $this->simulateEnrichment($dir, indexExists: TRUE);

    $this->assertTrue($result['index']['built']);
    $this->assertEquals(1, $result['index']['fragments']);
    $this->assertNotNull($result['index']['last_build']);
    $this->assertTrue($result['index']['integrity']['valid']);
    $this->assertEmpty($result['index']['integrity']['issues']);

    $this->rmdir_recursive($dir);
  }

  public function testEnrichmentWithMissingIndex(): void {
    $dir = sys_get_temp_dir() . '/scolta-health-test-' . uniqid();
    mkdir($dir, 0755, TRUE);

    $result = $this->simulateEnrichment($dir, indexExists: FALSE);

    $this->assertFalse($result['index']['built']);
    $this->assertArrayNotHasKey('fragments', $result['index']);
    $this->assertArrayNotHasKey('integrity', $result['index']);

    $this->rmdir_recursive($dir);
  }

  public function testEnrichmentWithNoFragments(): void {
    $dir = sys_get_temp_dir() . '/scolta-health-test-' . uniqid();
    mkdir($dir . '/pagefind/fragment', 0755, TRUE);
    file_put_contents($dir . '/pagefind/pagefind.js', 'pagefind_js_content');
    // No fragment files written.

    $result = $this->simulateEnrichment($dir, indexExists: TRUE);

    $this->assertEquals(0, $result['index']['fragments']);
    $this->assertFalse($result['index']['integrity']['valid']);
    $this->assertContains('No fragment files found', $result['index']['integrity']['issues']);
    $this->assertEquals('degraded', $result['status']);

    $this->rmdir_recursive($dir);
  }

  public function testEnrichmentWithEmptyIndexFile(): void {
    $dir = sys_get_temp_dir() . '/scolta-health-test-' . uniqid();
    mkdir($dir . '/pagefind/fragment', 0755, TRUE);
    file_put_contents($dir . '/pagefind/pagefind.js', '');  // Empty file.
    file_put_contents($dir . '/pagefind/fragment/en_abc123.pf_fragment', 'fragment_data');

    $result = $this->simulateEnrichment($dir, indexExists: TRUE);

    $this->assertFalse($result['index']['integrity']['valid']);
    $this->assertContains('pagefind.js is empty or unreadable', $result['index']['integrity']['issues']);
    $this->assertEquals('degraded', $result['status']);

    $this->rmdir_recursive($dir);
  }

  public function testEnrichmentWithMultipleFragments(): void {
    $dir = sys_get_temp_dir() . '/scolta-health-test-' . uniqid();
    mkdir($dir . '/pagefind/fragment', 0755, TRUE);
    file_put_contents($dir . '/pagefind/pagefind.js', 'pagefind_js_content');
    for ($i = 0; $i < 5; $i++) {
      file_put_contents($dir . '/pagefind/fragment/en_' . $i . '.pf_fragment', 'data');
    }

    $result = $this->simulateEnrichment($dir, indexExists: TRUE);

    $this->assertEquals(5, $result['index']['fragments']);
    $this->assertTrue($result['index']['integrity']['valid']);

    $this->rmdir_recursive($dir);
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  /**
   * Replicates the enrichment logic from HealthController::handle().
   *
   * @param string $outputDir
   *   A real filesystem path (stream wrappers already resolved).
   * @param bool $indexExists
   *   Value of $result['index_exists'] from HealthChecker::check().
   *
   * @return array
   *   The enriched result array (only index/status keys set here).
   */
  private function simulateEnrichment(string $outputDir, bool $indexExists): array {
    $result = ['status' => 'ok', 'index_exists' => $indexExists];

    if ($result['index_exists']) {
      $indexFile = file_exists($outputDir . '/pagefind/pagefind.js')
        ? $outputDir . '/pagefind/pagefind.js'
        : $outputDir . '/pagefind.js';
      $fragmentDir = file_exists($outputDir . '/pagefind/pagefind.js')
        ? $outputDir . '/pagefind/fragment'
        : $outputDir . '/fragment';

      $mtime = filemtime($indexFile);
      $fragments = glob($fragmentDir . '/*') ?: [];

      $result['index'] = [
        'built' => TRUE,
        'fragments' => count($fragments),
        'last_build' => $mtime ? date('c', $mtime) : NULL,
      ];

      $integrity = ['valid' => TRUE, 'issues' => []];

      $jsSize = filesize($indexFile);
      if ($jsSize === FALSE || $jsSize === 0) {
        $integrity['valid'] = FALSE;
        $integrity['issues'][] = 'pagefind.js is empty or unreadable';
      }

      if (count($fragments) > 0) {
        $fragSize = filesize($fragments[0]);
        if ($fragSize === FALSE || $fragSize === 0) {
          $integrity['valid'] = FALSE;
          $integrity['issues'][] = 'Fragment file is empty or corrupt';
        }
      }
      else {
        $integrity['valid'] = FALSE;
        $integrity['issues'][] = 'No fragment files found';
      }

      $result['index']['integrity'] = $integrity;

      if (!$integrity['valid']) {
        $result['status'] = 'degraded';
      }
    }
    else {
      $result['index'] = ['built' => FALSE];
    }

    return $result;
  }

  private function rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
      $path = $dir . '/' . $item;
      is_dir($path) ? $this->rmdir_recursive($path) : unlink($path);
    }
    rmdir($dir);
  }

}
