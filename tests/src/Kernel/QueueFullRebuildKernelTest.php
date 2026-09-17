<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * scolta_queue_full_rebuild() enqueues a marker and, forced, drops the manifest.
 *
 * @group scolta
 */
class QueueFullRebuildKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta'];

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

    $this->buildDir = sys_get_temp_dir() . '/scolta-queue-full-rebuild-' . uniqid();
    mkdir($this->buildDir, 0755, TRUE);
    file_put_contents($this->buildDir . '/timestamp-manifest.php', '<?php return [];');
    file_put_contents($this->buildDir . '/page-table.json', '{"node:42":0}');
    $this->config('scolta.settings')->set('pagefind.build_dir', $this->buildDir)->save();
    \Drupal::queue('scolta_rebuild')->deleteQueue();
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
   * Unforced: the marker is queued and the manifest is left alone.
   */
  public function testQueuesMarkerAndKeepsManifest(): void {
    $message = (string) scolta_queue_full_rebuild('test_reason');

    $this->assertFileExists($this->buildDir . '/timestamp-manifest.php');
    $this->assertStringContainsString('Kept the timestamp manifest', $message);
    $this->assertStringContainsString('drush queue:run scolta_rebuild', $message);
    $this->assertMarkerQueued();
  }

  /**
   * Forced: the manifest is deleted, nothing else in the state dir is.
   */
  public function testForceDropsOnlyTheManifest(): void {
    $message = (string) scolta_queue_full_rebuild('test_reason', TRUE);

    $this->assertFileDoesNotExist($this->buildDir . '/timestamp-manifest.php');
    $this->assertFileExists($this->buildDir . '/page-table.json');
    $this->assertStringContainsString('Dropped the timestamp manifest', $message);
    $this->assertMarkerQueued();

    // Forcing again with no manifest present says so and still queues.
    $message = (string) scolta_queue_full_rebuild('test_reason', TRUE);
    $this->assertStringContainsString('No timestamp manifest to drop', $message);
    $this->assertSame(2, \Drupal::queue('scolta_rebuild')->numberOfItems());
  }

  /**
   * Exactly one item is queued and it is the worker's full-rebuild marker.
   */
  protected function assertMarkerQueued(): void {
    $queue = \Drupal::queue('scolta_rebuild');
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertSame(['type' => 'test_reason'], $item->data, 'A payload without item IDs is what ScoltaRebuildWorker::foldPayload() treats as a full-rebuild request.');
    $queue->releaseItem($item);
  }

}
