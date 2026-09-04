<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * The scolta_cron() hook sweeps leftover retired-index trash.
 *
 * Publishing a new index parks the previous one in a `.scolta-trash-*`
 * directory and sweeps it after publishing (scolta-php's RetiredIndexTrash);
 * cron is the backstop for builds that died before their own sweep and for
 * the batch-UI path, which never sweeps. Cron runs by calling the 'cron'
 * service directly, in-process — no HTTP request is involved, so this needs
 * only a real container and a writable directory. (Not CronRunTrait's
 * cronRun(): that always calls drupalGet(), which needs the
 * HttpKernelUiHelperTrait KernelTestBase only pulls in as of Drupal 11 —
 * calling the service directly works identically on Drupal 10, our
 * lowest-supported core.)
 *
 * The output dir is a real temp path, not public://. Under public:// neither
 * half of this test could fail: KernelTestBase mounts it on vfsStream, so
 * scolta_cron() resolved it to a vfs:// URI that scolta-php's FilesystemDriver
 * rejects outright (`Stream wrappers are not allowed in file paths`) — cron
 * swallowed that exception and swept nothing — while glob(), which does not
 * work through stream wrappers, reported no trash whether or not any existed.
 * Both assertions passed with the sweep deleted from scolta.module entirely.
 *
 * @group scolta
 */
class CronCleanupKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta'];

  /**
   * A real filesystem directory standing in for the published index location.
   *
   * @var string
   */
  private string $outputDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta']);

    $this->outputDir = sys_get_temp_dir() . '/scolta-cron-cleanup-test-' . uniqid();
    mkdir($this->outputDir, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->outputDir)
      ->save();
  }

  /**
   * Runs cron in-process, the way every supported Drupal core version can.
   */
  private function cronRun(): void {
    \Drupal::service('cron')->run();
  }

  /**
   * The directory cron sweeps trash from.
   */
  private function outputDir(): string {
    return $this->outputDir;
  }

  /**
   * Creates a leftover trash directory with one stale fragment inside.
   */
  private function makeTrashDir(string $suffix = 'crashed'): string {
    $dir = $this->outputDir() . '/.scolta-trash-' . $suffix;
    mkdir($dir . '/fragment', 0755, TRUE);
    file_put_contents($dir . '/fragment/stale.pf_fragment', 'x');
    return $dir;
  }

  /**
   * Every leftover `.scolta-trash-*` directory under the output dir.
   */
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
   * Cleanup.cron_seconds = 0 disables the cron sweep entirely.
   */
  public function testCronCleanupDisabledWithZeroBudget(): void {
    $this->config('scolta.settings')->set('cleanup.cron_seconds', 0)->save();
    $trashDir = $this->makeTrashDir();

    $this->cronRun();

    $this->assertFileExists($trashDir . '/fragment/stale.pf_fragment');
  }

}
