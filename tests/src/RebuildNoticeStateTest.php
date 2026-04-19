<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Unit;

use Drupal\scolta\Batch\ScoltaBatchOperations;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the state-backed rebuild notice data structure.
 *
 * Pre-fix state: ScoltaBatchOperations::buildNoticeData() does not exist.
 * Running these tests pre-fix produces a PHP fatal "Call to undefined method".
 *
 * Post-fix: all assertions pass.
 *
 * @covers \Drupal\scolta\Batch\ScoltaBatchOperations::buildNoticeData
 */
class RebuildNoticeStateTest extends TestCase {

  /**
   * Pre-fix: method missing — Fatal.
   * Post-fix: returns array with all required keys — PASS.
   */
  public function testBuildNoticeDataHasRequiredKeys(): void {
    $data = ScoltaBatchOperations::buildNoticeData('ok', 'Index rebuilt: 42 pages.');

    $this->assertArrayHasKey('notice_id', $data, 'notice_id must be present for per-user dismissal tracking');
    $this->assertArrayHasKey('result', $data);
    $this->assertArrayHasKey('message', $data);
    $this->assertArrayHasKey('timestamp', $data);
  }

  /**
   * Pre-fix: method missing — Fatal.
   * Post-fix: notice_id is non-empty — PASS.
   */
  public function testBuildNoticeDataHasNonEmptyNoticeId(): void {
    $data = ScoltaBatchOperations::buildNoticeData('ok', '');

    $this->assertNotEmpty($data['notice_id'], 'notice_id must be non-empty so dismissal can be keyed to it');
  }

  /**
   * Pre-fix: method missing — Fatal.
   * Post-fix: two calls produce different notice_ids — PASS.
   *
   * This ensures a new rebuild always gets a fresh notice_id, making any
   * prior per-user dismissal irrelevant.
   */
  public function testBuildNoticeDataGeneratesUniqueNoticeIds(): void {
    $data1 = ScoltaBatchOperations::buildNoticeData('ok', 'first');
    $data2 = ScoltaBatchOperations::buildNoticeData('ok', 'second');

    $this->assertNotSame(
      $data1['notice_id'],
      $data2['notice_id'],
      'Each rebuild must get a unique notice_id so previous per-user dismissals do not suppress the new notice'
    );
  }

  /**
   * Pre-fix: method missing — Fatal.
   * Post-fix: result and message are preserved — PASS.
   */
  public function testBuildNoticeDataPreservesResultAndMessage(): void {
    $data = ScoltaBatchOperations::buildNoticeData('error', 'Something went wrong.');

    $this->assertSame('error', $data['result']);
    $this->assertSame('Something went wrong.', $data['message']);
  }

  /**
   * Pre-fix: method missing — Fatal.
   * Post-fix: timestamp is recent — PASS.
   */
  public function testBuildNoticeDataTimestampIsRecent(): void {
    $before = time();
    $data = ScoltaBatchOperations::buildNoticeData('ok', '');
    $after = time();

    $this->assertGreaterThanOrEqual($before, $data['timestamp']);
    $this->assertLessThanOrEqual($after, $data['timestamp']);
  }

}
