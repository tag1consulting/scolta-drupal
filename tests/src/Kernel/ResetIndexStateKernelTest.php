<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * scolta_reset_index_state() discards build state and queues one rebuild, once.
 *
 * The helper every update hook for an incompatible index format calls;
 * scolta_update_10007() is its first caller.
 *
 * @group scolta
 */
class ResetIndexStateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta', 'search_api'];

  /**
   * Real build directory, outside vfsStream.
   */
  protected string $buildDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta']);
    $this->container->get('module_handler')->loadInclude('scolta', 'install');

    $this->buildDir = sys_get_temp_dir() . '/scolta-reset-index-state-' . uniqid();
    mkdir($this->buildDir . '/chunks', 0755, TRUE);
    file_put_contents($this->buildDir . '/page-table.json', '{"42":0}');
    file_put_contents($this->buildDir . '/chunks/0.bin', 'x');
    $this->config('scolta.settings')->set('pagefind.build_dir', $this->buildDir)->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->buildDir)) {
      _scolta_empty_directory($this->buildDir);
      rmdir($this->buildDir);
    }
    parent::tearDown();
  }

  /**
   * The state is gone, the directory stays, and one rebuild is queued.
   */
  public function testEmptiesBuildStateAndQueuesOneRebuild(): void {
    $queue = \Drupal::queue('scolta_rebuild');
    $queue->deleteQueue();

    scolta_reset_index_state('test');

    $this->assertDirectoryExists($this->buildDir);
    $this->assertSame(['state-format'], array_values(array_diff(scandir($this->buildDir), ['.', '..'])), 'Every ledger, manifest and chunk file must be discarded; only the format marker remains.');
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame(['type' => 'test'], $item->data);
    $queue->releaseItem($item);

    // Idempotent: two update hooks in one deployment queue one rebuild.
    scolta_reset_index_state('test');
    scolta_update_10007();
    $this->assertSame(1, $queue->numberOfItems());
  }

  /**
   * A marker at or past the target format leaves the state alone.
   *
   * The scenario behind the marker: a database rollback or a production
   * database copied onto an environment whose files already carry the new
   * format re-runs the update hook, which used to discard an in-progress build.
   */
  public function testMarkerAtFormatSkipsResetAndQueue(): void {
    $queue = \Drupal::queue('scolta_rebuild');
    $queue->deleteQueue();
    file_put_contents($this->buildDir . '/state-format', (string) SCOLTA_STATE_FORMAT);
    // A failed resume's leftover must not enter into the decision.
    file_put_contents($this->buildDir . '/segment-outcome.json', '{"outcome":"memory_abort"}');

    $message = scolta_reset_index_state('test');

    $this->assertFileExists($this->buildDir . '/page-table.json');
    $this->assertFileExists($this->buildDir . '/chunks/0.bin');
    $this->assertSame(0, $queue->numberOfItems());
    $this->assertStringContainsString('already at state format ' . SCOLTA_STATE_FORMAT, (string) $message);
  }

  /**
   * A marker older than the target format still resets, and is advanced.
   */
  public function testOlderMarkerStillResets(): void {
    $queue = \Drupal::queue('scolta_rebuild');
    $queue->deleteQueue();
    file_put_contents($this->buildDir . '/state-format', (string) (SCOLTA_STATE_FORMAT - 1));

    scolta_reset_index_state('test');

    $this->assertFileDoesNotExist($this->buildDir . '/page-table.json');
    $this->assertSame(['state-format'], array_values(array_diff(scandir($this->buildDir), ['.', '..'])));
    $this->assertSame((string) SCOLTA_STATE_FORMAT, file_get_contents($this->buildDir . '/state-format'));
    $this->assertSame(1, $queue->numberOfItems());
  }

  /**
   * An empty, unmarked directory gets the marker; the build path writes it too.
   */
  public function testEmptyDirectoryGetsMarker(): void {
    _scolta_empty_directory($this->buildDir);

    scolta_reset_index_state('test');
    $this->assertSame((string) SCOLTA_STATE_FORMAT, file_get_contents($this->buildDir . '/state-format'));

    unlink($this->buildDir . '/state-format');
    scolta_mark_state_format($this->buildDir);
    $this->assertSame((string) SCOLTA_STATE_FORMAT, file_get_contents($this->buildDir . '/state-format'));
  }

  /**
   * Sites with auto_rebuild off drive builds from drush and get no queue item.
   */
  public function testQueuesNothingWhenAutoRebuildIsOff(): void {
    $this->config('scolta.settings')->set('pagefind.auto_rebuild', FALSE)->save();
    $queue = \Drupal::queue('scolta_rebuild');
    $queue->deleteQueue();

    scolta_reset_index_state('test');

    $this->assertSame(['state-format'], array_values(array_diff(scandir($this->buildDir), ['.', '..'])));
    $this->assertSame(0, $queue->numberOfItems());
  }

}
