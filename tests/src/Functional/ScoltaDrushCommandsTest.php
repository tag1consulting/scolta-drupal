<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Drush\TestTraits\DrushTestTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Proves the Scolta drush command surface is registered and wired.
 *
 * Runs real drush invocations against an installed site, replacing the
 * retired source-grep tests that asserted command names and aliases as
 * strings in ScoltaCommands.php. Commands that need real fixtures (a built
 * index, an AI key) get a presence check via `drush list`
 * or a smoke invocation only.
 *
 * @group scolta
 */
class ScoltaDrushCommandsTest extends BrowserTestBase {

  use DrushTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The scolta:status command emits YAML on stdout with all its sections.
   */
  public function testStatusReportsItsSections(): void {
    $this->drush('scolta:status');
    $status = Yaml::parse($this->getOutput());
    $this->assertIsArray($status, 'scolta:status must emit parseable YAML');
    foreach ([
      'build_directory',
      'build',
      'pagefind_index',
      'ai_provider',
      'cache',
    ] as $section) {
      $this->assertArrayHasKey($section, $status,
        "scolta:status must report the {$section} section");
    }
    // Groupings are nested maps, not flattened lines.
    $this->assertFalse($status['pagefind_index']['built']);
    $this->assertIsInt($status['cache']['generation']);
    // A fresh site has no recorded Amazee auth failure, but the field must be
    // reported so an operator does not have to check /health separately to
    // learn a provider's stored credentials are being rejected.
    $this->assertArrayHasKey('auth_failing', $status['ai_provider']);
    $this->assertFalse($status['ai_provider']['auth_failing']);
    $this->assertNull($status['ai_provider']['auth_failing_since']);
  }

  /**
   * scolta:status reports a half-finished build, not just the built index.
   *
   * Writes the manifest a build segment leaves behind when it stops without
   * finishing, which is exactly the state an operator cannot see from the
   * pagefind_index section.
   */
  public function testStatusReportsAnInProgressBuild(): void {
    $dir = $this->container->get('file_system')->realpath('public://scolta-build');
    mkdir($dir, 0777, TRUE);
    file_put_contents($dir . '/manifest.json', json_encode([
      'status' => 'building',
      'started_at' => '2026-09-10T22:00:00+00:00',
      'segment' => 2,
      'total_pages' => 1000,
      'chunk_size' => 100,
      'chunks_written' => 4,
      'pages_processed' => 400,
    ]));

    file_put_contents($dir . '/segment-outcome.json', json_encode([
      'success' => FALSE,
      'error' => 'memory_abort',
      'pages_processed' => 180,
      'pid' => 999,
      'recorded_at' => '2026-09-10T22:31:08+00:00',
    ]));

    $this->drush('scolta:status');
    $status = Yaml::parse($this->getOutput());

    $this->assertSame(2, $status['build']['segment']);
    $this->assertSame(400, $status['build']['pages_processed']);
    $this->assertSame('40%', $status['build']['progress']);
    // No process holds the lock, so the build is stalled, not running.
    $this->assertSame('interrupted', $status['build']['activity']);
    // The segment yielded on memory rather than failing outright, which is
    // the difference an operator cannot get from the manifest.
    $this->assertSame('memory_abort', $status['build']['last_segment']['error']);
    $this->assertFalse($status['build']['last_segment']['success']);

    // The same report through Drush's formatters, for machine consumption.
    $this->drush('scolta:status', [], ['format' => 'json']);
    $json = json_decode($this->getOutput(), TRUE);
    $this->assertSame(2, $json['build']['segment']);
    $this->assertSame('memory_abort', $json['build']['last_segment']['error']);
  }

  /**
   * scolta:status reports a recorded Amazee auth failure.
   *
   * Writes the same cache marker KeyExpiryRecovery records on an
   * authentication rejection, under the bare key documented in
   * scolta-php's docs/HEALTH_REFERENCE.md, and confirms status surfaces it
   * without requiring a separate /health request.
   */
  public function testStatusReportsARecordedAuthFailure(): void {
    $this->container->get('cache.default')->set('scolta_amazee_auth_failure', time());

    $this->drush('scolta:status');
    $status = Yaml::parse($this->getOutput());

    $this->assertTrue($status['ai_provider']['auth_failing']);
    $this->assertNotNull($status['ai_provider']['auth_failing_since']);
  }

  /**
   * The scolta:check-setup command reports its verdict on a fresh site.
   *
   * checkSetup() logs each check and a summary line but never throws, so
   * drush exits 0 even when a critical check fails on a fresh site; the
   * assertion is on the summary wording, which appears on both outcomes.
   */
  public function testCheckSetupRunsAndReports(): void {
    $this->drush('scolta:check-setup');
    $this->assertStringContainsString('critical checks', $this->getErrorOutput(),
      'scolta:check-setup must report a pass/fail summary');
  }

  /**
   * The scolta:clear-cache command runs the full wiring: state, cache, logger.
   */
  public function testClearCacheSucceeds(): void {
    $this->drush('scolta:clear-cache');
    $this->assertStringContainsString('Scolta caches cleared', $this->getErrorOutput());
  }

  /**
   * An alias resolves to its command.
   */
  public function testStatusAliasResolves(): void {
    $this->drush('sst');
    $this->assertStringContainsString('ai_provider:', $this->getOutput(),
      'The sst alias must invoke scolta:status');
  }

}
