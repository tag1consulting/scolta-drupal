<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that the health endpoint trims its payload for anonymous callers.
 *
 * Policy: the health route stays reachable anonymously so uptime monitors
 * always work, but the full diagnostic payload (provider, index integrity,
 * fragment counts) requires 'administer scolta'. Anonymous callers receive
 * exactly ['status' => ...].
 *
 * The controller cannot be instantiated without a Drupal bootstrap, so tests
 * use the same two strategies as HealthControllerIndexDetailTest: source
 * analysis plus a functional replication of the trim logic.
 */
class HealthPayloadTrimTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // Routing: the health route must be anonymously reachable.
  // -------------------------------------------------------------------

  public function testHealthRouteIsAnonymouslyReachable(): void {
    $routing = PackageManifest::routes();

    $this->assertSame(
      'TRUE',
      $routing['scolta.health']['requirements']['_access'] ?? NULL,
      'Health route must use _access TRUE so monitors work without a permission grant'
    );
    $this->assertArrayNotHasKey(
      '_permission',
      $routing['scolta.health']['requirements'],
      'Health route must not require a permission — the controller trims the payload instead'
    );
  }

  // -------------------------------------------------------------------
  // Source analysis: the controller gates detail on 'administer scolta'.
  // -------------------------------------------------------------------

  public function testControllerTrimsDetailWithoutAdministerScolta(): void {
    $src = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Controller/HealthController.php');

    // The frontend's own admin permission: /health is a scolta_ui route, and a
    // frontend-only install has no 'administer scolta' to check against.
    $this->assertStringContainsString(
      "hasPermission('administer scolta ui')",
      $src,
      'HealthController must gate the full payload on the administer scolta ui permission'
    );
    $this->assertStringContainsString(
      "new JsonResponse(['status' => \$result['status']])",
      $src,
      'HealthController must return exactly the status key to unauthorized callers'
    );
  }

  public function testControllerDoesNotUseFloodControl(): void {
    $src = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Controller/HealthController.php');

    // The health endpoint is excluded from AI-endpoint flood limits — a
    // throttled monitor is worse than no monitor. It must not extend the
    // flood-aware AI controller base or call the flood service.
    $this->assertStringNotContainsString('AiApiControllerBase', $src);
    $this->assertStringNotContainsString('flood', $src);
  }

  // -------------------------------------------------------------------
  // Functional: replicate the trim logic and assert payload shapes.
  // -------------------------------------------------------------------

  /**
   * Replicates HealthController::handle()'s payload trim.
   *
   * @param array<string, mixed> $result
   *   The fully enriched health report.
   * @param bool $hasAdminPermission
   *   Whether the caller has 'administer scolta'.
   *
   * @return array<string, mixed>
   *   The response payload.
   */
  private function shapePayload(array $result, bool $hasAdminPermission): array {
    if (!$hasAdminPermission) {
      return ['status' => $result['status']];
    }
    return $result;
  }

  /**
   * @return array<string, mixed>
   */
  private function fullReport(string $status = 'ok'): array {
    return [
      'status' => $status,
      'ai_provider' => 'anthropic',
      'ai_configured' => TRUE,
      'index_exists' => TRUE,
      'index' => [
        'built' => TRUE,
        'fragments' => 42,
        'last_build' => '2026-06-11T00:00:00+00:00',
        'integrity' => ['valid' => TRUE, 'issues' => []],
      ],
    ];
  }

  public function testAnonymousPayloadContainsExactlyStatus(): void {
    $payload = $this->shapePayload($this->fullReport(), FALSE);

    $this->assertSame(['status'], array_keys($payload));
    $this->assertSame('ok', $payload['status']);
  }

  public function testAnonymousPayloadStillReflectsDegradedStatus(): void {
    $payload = $this->shapePayload($this->fullReport('degraded'), FALSE);

    $this->assertSame(['status' => 'degraded'], $payload);
  }

  public function testAdminPayloadContainsFullDetail(): void {
    $full = $this->fullReport();
    $payload = $this->shapePayload($full, TRUE);

    $this->assertSame($full, $payload);
    foreach (['ai_provider', 'ai_configured', 'index_exists', 'index'] as $key) {
      $this->assertArrayHasKey($key, $payload);
    }
  }

}
