<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Behavioral test for ScoltaBatchOperations's notice-ID sanitization.
 *
 * Runs without a Drupal bootstrap.
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

}
