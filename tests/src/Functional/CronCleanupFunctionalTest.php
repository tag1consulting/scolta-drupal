<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\Traits\Core\CronRunTrait;

/**
 * hook_cron() sweeps leftover retired-index trash.
 *
 * Publishing a new index parks the previous one in a `.scolta-trash-*`
 * directory and sweeps it after publishing (scolta-php's RetiredIndexTrash);
 * cron is the backstop for builds that died before their own sweep and for
 * the batch-UI path, which never sweeps. scolta_cron() needs a real
 * \Drupal::config()/service() container, which the plain-unit-test job does
 * not provide (no real drupal/core installed there) — a real installed site
 * is what a hook implementation needs to be tested against.
 *
 * @group scolta
 */
class CronCleanupFunctionalTest extends BrowserTestBase {

  use CronRunTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The default pagefind.output_dir, resolved to a real path.
   */
  private function outputDir(): string {
    return \Drupal::config('scolta.settings')->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
  }

  private function makeTrashDir(string $suffix = 'crashed'): string {
    $dir = $this->outputDir() . '/.scolta-trash-' . $suffix;
    mkdir($dir . '/fragment', 0755, TRUE);
    file_put_contents($dir . '/fragment/stale.pf_fragment', 'x');
    return $dir;
  }

  private function trashDirs(): array {
    return glob($this->outputDir() . '/.scolta-trash-*') ?: [];
  }

  /**
   * Cron sweeps leftover trash and leaves the live index untouched.
   */
  public function testCronSweepsLeftoverTrash(): void {
    mkdir($this->outputDir() . '/pagefind', 0755, TRUE);
    file_put_contents($this->outputDir() . '/pagefind/pagefind-entry.json', '{}');
    $this->makeTrashDir();

    $this->cronRun();

    $this->assertSame([], $this->trashDirs());
    $this->assertFileExists($this->outputDir() . '/pagefind/pagefind-entry.json');
  }

  /**
   * cleanup.cron_seconds = 0 disables the cron sweep entirely.
   */
  public function testCronCleanupIsDisabledByAZeroBudget(): void {
    $this->config('scolta.settings')->set('cleanup.cron_seconds', 0)->save();
    $trashDir = $this->makeTrashDir();

    $this->cronRun();

    $this->assertFileExists($trashDir . '/fragment/stale.pf_fragment');
  }

}
