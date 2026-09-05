<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\scolta\Controller\HealthController;
use PHPUnit\Framework\TestCase;

/**
 * The index-integrity spot check is the adapter's own fault detection, merged
 * into a payload scolta-php's HealthChecker owns.
 *
 * Exercises HealthController::degradeFor() directly against both payload
 * shapes it must handle: the pre-1.5.0 scolta-php shape with no
 * `status_reasons` key, and the shape scolta-php 1.5.0+ produces.
 */
class HealthStatusReasonsTest extends TestCase {

  /**
   * The payload an older scolta-php produces: no `status_reasons` key.
   */
  private function legacyReport(string $status = 'ok'): array {
    return [
      'status' => $status,
      'ai_provider' => 'anthropic',
      'ai_configured' => TRUE,
      'index_exists' => TRUE,
    ];
  }

  /**
   * The payload scolta-php 1.5.0+ produces.
   */
  private function reasonsReport(array $reasons = []): array {
    return [
      'status' => $reasons === [] ? 'ok' : 'degraded',
      'status_reasons' => $reasons,
      'ai_provider' => '',
      'ai_configured' => FALSE,
      'index_exists' => TRUE,
    ];
  }

  // -------------------------------------------------------------------
  // Payload shape without status_reasons (an older scolta-php).
  // -------------------------------------------------------------------

  public function testLegacyPayloadIsDegradedWithoutGainingAReasonsKey(): void {
    $shaped = HealthController::degradeFor(
      $this->legacyReport(),
      HealthController::REASON_INDEX_INTEGRITY_INVALID
    );

    $this->assertSame('degraded', $shaped['status']);
    $this->assertArrayNotHasKey(
      'status_reasons',
      $shaped,
      'The adapter must not invent a key the installed scolta-php does not produce.'
    );
  }

  public function testLegacyPayloadKeepsEveryOtherField(): void {
    $before = $this->legacyReport();
    $shaped = HealthController::degradeFor(
      $before,
      HealthController::REASON_INDEX_INTEGRITY_INVALID
    );

    $this->assertSame(
      array_diff_key($before, ['status' => NULL]),
      array_diff_key($shaped, ['status' => NULL])
    );
  }

  // -------------------------------------------------------------------
  // Payload shape with status_reasons (scolta-php 1.5.0+).
  // -------------------------------------------------------------------

  public function testReasonIsAppendedToAnOtherwiseHealthyPayload(): void {
    $shaped = HealthController::degradeFor(
      $this->reasonsReport(),
      HealthController::REASON_INDEX_INTEGRITY_INVALID
    );

    $this->assertSame('degraded', $shaped['status']);
    $this->assertSame(['index_integrity_invalid'], $shaped['status_reasons']);
  }

  public function testReasonIsAppendedBesideTheCheckersOwnReasons(): void {
    $shaped = HealthController::degradeFor(
      $this->reasonsReport(['index_stale_artifact_urls', 'ai_auth_failing']),
      HealthController::REASON_INDEX_INTEGRITY_INVALID
    );

    $this->assertSame(
      ['index_stale_artifact_urls', 'ai_auth_failing', 'index_integrity_invalid'],
      $shaped['status_reasons'],
      'Existing reasons must survive; the adapter appends rather than overwrites.'
    );
    $this->assertSame('degraded', $shaped['status']);
  }

  public function testStatusIsNeverEmptyOfReasonsWhileNonOk(): void {
    $shaped = HealthController::degradeFor(
      $this->reasonsReport(),
      HealthController::REASON_INDEX_INTEGRITY_INVALID
    );

    // status_reasons is empty exactly when status is 'ok'; a degraded
    // payload with an empty list is the contradiction this prevents.
    $this->assertNotSame('ok', $shaped['status']);
    $this->assertNotSame([], $shaped['status_reasons']);
  }

  public function testTheSameReasonIsNotRecordedTwice(): void {
    $once = HealthController::degradeFor(
      $this->reasonsReport(),
      HealthController::REASON_INDEX_INTEGRITY_INVALID
    );
    $twice = HealthController::degradeFor(
      $once,
      HealthController::REASON_INDEX_INTEGRITY_INVALID
    );

    $this->assertSame($once, $twice);
  }

  // -------------------------------------------------------------------
  // Severity.
  // -------------------------------------------------------------------

  public function testAMoreSevereStatusIsNotDemoted(): void {
    // scolta-php reports only 'ok' and 'degraded' today; the flat overwrite
    // this replaces is why it did not add a more severe third value.
    $report = $this->reasonsReport(['index_missing']);
    $report['status'] = 'unavailable';

    $shaped = HealthController::degradeFor(
      $report,
      HealthController::REASON_INDEX_INTEGRITY_INVALID
    );

    $this->assertSame('unavailable', $shaped['status']);
    $this->assertSame(
      ['index_missing', 'index_integrity_invalid'],
      $shaped['status_reasons'],
      'A status left alone must still gain the reason.'
    );
  }

  public function testAnAlreadyDegradedStatusStaysDegraded(): void {
    $shaped = HealthController::degradeFor(
      $this->reasonsReport(['index_stale_artifact_urls']),
      HealthController::REASON_INDEX_INTEGRITY_INVALID
    );

    $this->assertSame('degraded', $shaped['status']);
  }

}
