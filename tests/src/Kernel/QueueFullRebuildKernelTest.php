<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * The update-hook helper enqueues an untargeted request, forced or not.
 *
 * Covers scolta_queue_full_rebuild().
 *
 * What the worker does with the flag is covered by
 * ScoltaRebuildWorkerResumeKernelTest::testAForcedRequestStaysForcedAcrossTicks().
 *
 * @group scolta
 */
class QueueFullRebuildKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta', 'scolta_ui'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta']);
    \Drupal::queue('scolta_rebuild')->deleteQueue();
  }

  /**
   * The payload carries the reason and the flag; the message names the flag.
   */
  public function testQueuesRequestCarryingTheForceFlag(): void {
    $message = (string) scolta_queue_full_rebuild('test_reason');
    $this->assertStringContainsString('drush queue:run scolta_rebuild', $message);
    $this->assertStringNotContainsString('--force', $message);
    $this->assertSame([['type' => 'test_reason', 'force' => FALSE]], $this->queued());

    \Drupal::queue('scolta_rebuild')->deleteQueue();
    $message = (string) scolta_queue_full_rebuild('test_reason', TRUE);
    $this->assertStringContainsString('reloads every entity', $message);
    $this->assertStringContainsString('drush scolta:build --force', $message);
    $this->assertSame([['type' => 'test_reason', 'force' => TRUE]], $this->queued());
  }

  /**
   * Every claimable payload, released again.
   */
  protected function queued(): array {
    $queue = \Drupal::queue('scolta_rebuild');
    $items = [];
    while (is_object($item = $queue->claimItem())) {
      $items[] = $item;
    }
    foreach ($items as $item) {
      $queue->releaseItem($item);
    }
    return array_map(fn($item) => $item->data, $items);
  }

}
