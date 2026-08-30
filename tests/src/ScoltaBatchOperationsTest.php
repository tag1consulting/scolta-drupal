<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\scolta\Batch\ScoltaBatchOperations;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral and structural tests for ScoltaBatchOperations.
 *
 * Runs without a Drupal bootstrap; structure is checked via reflection,
 * never by asserting on source text.
 */
class ScoltaBatchOperationsTest extends TestCase {

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
   * loadAndProcessChunk accepts entity IDs, not pre-loaded ContentItems.
   *
   * The signature must take an array of entity IDs so that entity loading
   * stays inside the batch request rather than in the initial web request
   * (the original timeout bug). Drupal's Batch API passes &$context last.
   */
  public function testLoadAndProcessChunkAcceptsEntityIds(): void {
    $ref = new \ReflectionMethod(ScoltaBatchOperations::class, 'loadAndProcessChunk');
    $params = $ref->getParameters();

    $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $params);
    $this->assertSame(
      ['chunkIdx', 'entityIds', 'totalCount', 'siteName', 'config', 'context'],
      $names,
      'loadAndProcessChunk() must accept $entityIds (not $chunk or $items) to make the entity-loading intent explicit'
    );

    $this->assertSame('array', (string) $params[1]->getType(),
      'The $entityIds parameter must be an array of IDs, not pre-loaded ContentItems');
    $this->assertTrue($params[5]->isPassedByReference(),
      'The Batch API $context parameter must be by reference');
  }

}
