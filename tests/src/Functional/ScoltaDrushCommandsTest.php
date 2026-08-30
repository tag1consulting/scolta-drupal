<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Drush\TestTraits\DrushTestTrait;

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
   * scolta:status runs against a fresh site and reports its sections.
   */
  public function testStatusReportsItsSections(): void {
    $this->drush('scolta:status');
    // Drush logger output goes to stderr.
    $output = $this->getErrorOutput();
    foreach ([
      '--- Search API ---',
      '--- Indexer ---',
      '--- Build Directory ---',
      '--- Pagefind Index ---',
      '--- AI Provider ---',
    ] as $section) {
      $this->assertStringContainsString($section, $output,
        "scolta:status must report the {$section} section");
    }
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
    $this->assertStringContainsString('--- AI Provider ---', $this->getErrorOutput(),
      'The sst alias must invoke scolta:status');
  }

}
