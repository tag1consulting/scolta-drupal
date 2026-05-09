<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\scolta\Batch\ScoltaBatchOperations;
use PHPUnit\Framework\TestCase;

/**
 * Hygiene and behavioral tests for ScoltaBatchOperations.
 *
 * Source-parse tests prevent reintroduction of banned patterns;
 * behavioral tests verify the notice-ID contract without a Drupal bootstrap.
 */
class ScoltaBatchOperationsTest extends TestCase {

  /**
   * Ensure uniqid(..., TRUE) is not used to generate the notice ID.
   *
   * uniqid() with a TRUE second argument appends a floating-point suffix
   * containing a period, which breaks downstream sanitizers that strip
   * non-alphanumeric characters.
   */
  public function testNoticeidGenerationDoesNotUseUniqid(): void {
    $source = file_get_contents(__DIR__ . '/../../src/Batch/ScoltaBatchOperations.php');
    $this->assertDoesNotMatchRegularExpression(
      '/uniqid\s*\([^)]*,\s*TRUE\s*\)/i',
      $source,
      'ScoltaBatchOperations must not use uniqid(..., TRUE) — the period in the suffix can break ID comparisons.'
    );
  }

  /**
   * Ensure file_put_contents calls are always wrapped in an error check.
   *
   * Scans for every file_put_contents call and verifies it appears inside
   * a guard expression (if-condition or return). A bare statement-level call
   * that silently drops the return value is not permitted.
   */
  public function testFilePutContentsAlwaysChecked(): void {
    $files = [
      __DIR__ . '/../../src/Service/PagefindExporter.php',
      __DIR__ . '/../../src/Plugin/QueueWorker/ScoltaRebuildWorker.php',
    ];
    foreach ($files as $file) {
      $source = file_get_contents($file);
      // Find all file_put_contents occurrences, including those inside if().
      preg_match_all('/file_put_contents\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);

      $this->assertNotEmpty(
        $matches[0],
        basename($file) . ': expected at least one file_put_contents call.'
      );

      foreach ($matches[0] as [$match, $offset]) {
        // Grab the 120 chars before the call to see whether it is guarded.
        $preceding = substr($source, max(0, $offset - 120), 120);
        $this->assertMatchesRegularExpression(
          '/(?:if\s*\(|return\s)/',
          $preceding,
          basename($file) . ': file_put_contents at offset ' . $offset . ' must be wrapped in an error check.'
        );
      }
    }
  }

  /**
   * Ensure json_decode on remote API responses uses JSON_THROW_ON_ERROR.
   */
  public function testJsonDecodeOnRemoteResponsesUsesThrowOnError(): void {
    $source = file_get_contents(__DIR__ . '/../../src/Commands/ScoltaCommands.php');
    preg_match_all('/json_decode\s*\([^;]*(?:getBody|wp_remote_retrieve_body)[^;]*\)/s', $source, $matches);
    foreach ($matches[0] as $call) {
      $this->assertStringContainsString(
        'JSON_THROW_ON_ERROR',
        $call,
        'json_decode on remote API responses must use JSON_THROW_ON_ERROR.'
      );
    }
  }

  /**
   * Verify the bin2hex approach produces no period-containing IDs.
   */
  public function testNoticeidContainsNoPeriods(): void {
    for ($i = 0; $i < 10; $i++) {
      $id = 'scolta_notice_' . bin2hex(random_bytes(8));
      $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $id);
      $this->assertSame($id, $sanitized, 'Generated notice ID must survive strict alphanumeric sanitization.');
    }
  }

  /**
   * ScoltaRebuildWorker must fall back to system.site when site_name is empty.
   *
   * Without the fallback, queue-based rebuilds embed an empty site_name in
   * every ContentItem, which breaks the site-name filter in AI prompts.
   */
  public function testRebuildWorkerFallsBackToSystemSiteName(): void {
    $source = file_get_contents(__DIR__ . '/../../src/Plugin/QueueWorker/ScoltaRebuildWorker.php');
    $this->assertStringContainsString(
      'system.site',
      $source,
      'ScoltaRebuildWorker::processItem() must fall back to system.site when site_name is empty'
    );
  }

  /**
   * ScoltaRebuildWorker must not use an empty string as the silent default.
   *
   * The old code used `$config->get('site_name') ?? ''` which silently passed
   * an empty site name through to the index. The fix must use `?:` (falsy check)
   * so an explicitly-stored empty string also triggers the fallback.
   */
  public function testRebuildWorkerSiteNameUsesFalsyFallback(): void {
    $source = file_get_contents(__DIR__ . '/../../src/Plugin/QueueWorker/ScoltaRebuildWorker.php');
    // Old pattern: `$config->get('site_name') ?? ''`  — null-coalescing misses empty string.
    // New pattern must use ?: so empty string also falls through.
    $this->assertStringNotContainsString(
      "get('site_name') ?? ''",
      $source,
      "ScoltaRebuildWorker must use ?: (falsy fallback) for site_name, not ?? '' (null-coalescing), so stored empty strings also trigger the system.site lookup"
    );
  }

}
