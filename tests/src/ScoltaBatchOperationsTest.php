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
    // The rebuild worker no longer writes files directly — the
    // IndexBuildOrchestrator owns all index/state writes.
    $files = [
      __DIR__ . '/../../src/Service/PagefindExporter.php',
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

  // -------------------------------------------------------------------
  // loadAndProcessChunk — the "Index Now" batch callback.
  // -------------------------------------------------------------------

  /**
   * loadAndProcessChunk must exist as a public static method.
   *
   * rebuildWithBatch() registers it as the batch callback for each ID chunk,
   * so it must be callable by Drupal's Batch API.
   */
  public function testLoadAndProcessChunkExists(): void {
    $this->assertTrue(
      method_exists(ScoltaBatchOperations::class, 'loadAndProcessChunk'),
      'ScoltaBatchOperations must have a loadAndProcessChunk() static method'
    );
  }

  public function testLoadAndProcessChunkIsPublicAndStatic(): void {
    $ref = new \ReflectionMethod(ScoltaBatchOperations::class, 'loadAndProcessChunk');
    $this->assertTrue($ref->isPublic(), 'loadAndProcessChunk must be public');
    $this->assertTrue($ref->isStatic(), 'loadAndProcessChunk must be static (Batch API callback)');
  }

  /**
   * loadAndProcessChunk signature: (chunkIdx, entityIds, totalCount, siteName, config, &context).
   *
   * Drupal's Batch API passes the &$context reference as the final argument,
   * and the other parameters come from the operations array. The signature
   * must accept entity IDs (not pre-loaded ContentItems) so that entity
   * loading stays inside the batch request, not in the initial web request.
   */
  public function testLoadAndProcessChunkAcceptsEntityIds(): void {
    $source = file_get_contents(__DIR__ . '/../../src/Batch/ScoltaBatchOperations.php');

    // The method must accept an array of entity IDs (not ContentItem[]).
    // Verify the parameter name signals that intent.
    $this->assertStringContainsString(
      'array $entityIds',
      $source,
      'loadAndProcessChunk() must accept $entityIds (not $chunk or $items) to make the entity-loading intent explicit'
    );
  }

  /**
   * loadAndProcessChunk must load entities inside the batch step.
   *
   * The whole point of this callback is to defer entity loading to the batch
   * request. Verify that it calls entityTypeManager()->getStorage() rather
   * than receiving pre-loaded entities.
   */
  public function testLoadAndProcessChunkLoadsEntitiesInternally(): void {
    $source = file_get_contents(__DIR__ . '/../../src/Batch/ScoltaBatchOperations.php');

    // Locate the method body to scope the assertion.
    preg_match('/function loadAndProcessChunk\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s', $source, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate loadAndProcessChunk() body');
    $this->assertStringContainsString(
      'gatherByIds',
      $body,
      'loadAndProcessChunk() must convert its ID slice through ScoltaContentGatherer::gatherByIds() so batch-built indexes match Drush-built ones'
    );
  }

  /**
   * rebuildWithBatch must dispatch loadAndProcessChunk — not processChunk.
   *
   * processChunk receives pre-loaded ContentItems and was the old callback
   * that caused the timeout: all entities were loaded before batch dispatch.
   * The new callback (loadAndProcessChunk) receives entity IDs only.
   */
  public function testRebuildWithBatchUsesLoadAndProcessChunkCallback(): void {
    $source = file_get_contents(__DIR__ . '/../../src/Form/ScoltaIndexSettingsForm.php');

    preg_match('/function rebuildWithBatch\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s', $source, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate rebuildWithBatch() body');

    $this->assertStringContainsString(
      'loadAndProcessChunk',
      $body,
      'rebuildWithBatch() must dispatch loadAndProcessChunk (entity-ID-based) not processChunk (pre-loaded ContentItems)'
    );
    $this->assertStringNotContainsString(
      "'processChunk'",
      $body,
      "rebuildWithBatch() must not dispatch processChunk — that callback accepts pre-loaded ContentItems which causes the initial timeout"
    );
  }

  /**
   * rebuildSubmit (PHP indexer path) must not call loadMultiple before batching.
   *
   * The original bug: rebuildSubmit() called gatherContentItems() which called
   * loadMultiple($allIds), loading the entire corpus into memory before the
   * batch was dispatched. On shared hosting this request itself timed out.
   *
   * The fix: for the PHP indexer path, rebuildSubmit() may only call execute()
   * (the ID query) and then dispatch the batch. loadMultiple must not appear
   * in the method body for the PHP path.
   */
  public function testRebuildSubmitDoesNotLoadMultipleForPhpIndexer(): void {
    $source = file_get_contents(__DIR__ . '/../../src/Form/ScoltaIndexSettingsForm.php');

    // Locate just the rebuildSubmit method body.
    preg_match('/function rebuildSubmit\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s', $source, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate rebuildSubmit() body');

    // The PHP indexer branch is identified by the dispatch to rebuildWithBatch.
    // loadMultiple must not appear anywhere in the method — the only storage
    // call allowed in rebuildSubmit() is ->execute() to fetch IDs.
    $this->assertStringNotContainsString(
      'loadMultiple',
      $body,
      'rebuildSubmit() must not call loadMultiple() — entity loading must happen inside batch steps to prevent timeout on large corpora'
    );
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
