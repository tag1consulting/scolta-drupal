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
 * index, a pagefind binary, an AI key) get a presence check via `drush list`
 * or a smoke invocation only.
 *
 * @group scolta
 */
class ScoltaDrushCommandsTest extends BrowserTestBase {

  use DrushTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * scolta:status emits parseable YAML on stdout with all its sections.
   */
  public function testStatusReportsItsSections(): void {
    $this->drush('scolta:status');
    $status = Yaml::parse($this->getOutput());
    $this->assertIsArray($status, 'scolta:status must emit parseable YAML');
    foreach ([
      'search_api',
      'indexer',
      'build_directory',
      'pagefind_index',
      'ai_provider',
      'cache',
    ] as $section) {
      $this->assertArrayHasKey($section, $status,
        "scolta:status must report the {$section} section");
    }
    // Groupings are nested maps, not flattened lines.
    $this->assertSame('php', $status['indexer']['active']);
    $this->assertFalse($status['pagefind_index']['built']);
    $this->assertIsInt($status['cache']['generation']);
  }

  /**
   * scolta:check-setup runs and reports its verdict on a fresh site.
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
   * scolta:clear-cache runs the full wiring: state, cache, logger.
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
