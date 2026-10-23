<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\canvas\Entity\Page;

/**
 * Canvas page edits reach the incremental update queue with the right IDs.
 *
 * The auto-rebuild entity hooks queue the item IDs an edit touches, and the
 * worker removes any expected ID it does not gather. For a Canvas page that
 * means 'canvas_page:N' and 'canvas_page:N-es', the same IDs the gatherer
 * writes, so an unpublished or deleted page leaves the index.
 *
 * @group scolta
 */
class CanvasPageIncrementalKernelTest extends CanvasPageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $realDir = sys_get_temp_dir() . '/scolta-kernel-test-' . uniqid();
    mkdir($realDir, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.auto_rebuild', TRUE)
      ->set('pagefind.output_dir', $realDir . '/output')
      ->set('pagefind.build_dir', $realDir . '/build')
      ->save();
  }

  /**
   * Insert, update and delete each queue the page's item IDs.
   */
  public function testQueueItemIds(): void {
    $page = $this->createPage('Results', $this->englishText());
    $id = 'canvas_page:' . $page->id();
    $this->assertSame([['insert', [$id]]], $this->takeQueued());

    $page->addTranslation('es', [
      'title' => 'Resultados',
      'status' => TRUE,
      'components' => $this->resultsTree($this->spanishText()),
    ])->save();
    $this->assertSame([['update', [$id, $id . '-es']]], $this->takeQueued());

    // Removing a translation still names its page, so the worker removes it.
    $page->removeTranslation('es');
    $page->save();
    $this->assertSame([['update', [$id, $id . '-es']]], $this->takeQueued());

    $page->delete();
    $this->assertSame([['delete', [$id]]], $this->takeQueued());
  }

  /**
   * A page that is not of a configured type queues nothing.
   */
  public function testNotIndexedTypeQueuesNothing(): void {
    $this->config('scolta.settings')->set('entity_types', ['node' => []])->save();
    $this->createPage('Results', $this->englishText());
    $this->assertSame([], $this->takeQueued());
  }

  /**
   * Publishing adds the page to the index; unpublishing removes it.
   */
  public function testUnpublishRemovesThePage(): void {
    $page = $this->createPage('Results', $this->englishText(), [], $this->spanishText());
    $this->takeQueued();
    $this->runWorker(['type' => 'install']);
    $this->assertSame(['Results', 'Results (es)'], $this->indexedTitles());

    $page->setUnpublished()->save();
    $page->getTranslation('es')->setUnpublished()->save();
    $this->drainQueue();
    $this->assertSame([], $this->indexedTitles());

    $page = Page::load($page->id());
    $page->setPublished()->save();
    $this->drainQueue();
    $this->assertSame(['Results'], $this->indexedTitles());
  }

  /**
   * The queued [op, item_ids] pairs since the last call, removing them.
   */
  protected function takeQueued(): array {
    $queue = $this->container->get('queue')->get('scolta_rebuild');
    $taken = [];
    while ($item = $queue->claimItem()) {
      $taken[] = [$item->data['op'], $item->data['item_ids']];
      $queue->deleteItem($item);
    }
    return $taken;
  }

  /**
   * Run every queued request through the worker in one change set.
   */
  protected function drainQueue(): void {
    $queue = $this->container->get('queue')->get('scolta_rebuild');
    $item = $queue->claimItem();
    $this->assertNotFalse($item, 'An edit must queue a request');
    $this->runWorker($item->data);
    $queue->deleteItem($item);
  }

  /**
   * Run the queue worker without the debounce.
   */
  protected function runWorker(array $data): void {
    \Drupal::state()->delete('scolta.rebuild_requested_at');
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance('scolta_rebuild')
      ->processItem($data);
  }

  /**
   * The titles of the pages the published index still serves, sorted.
   *
   * @return string[]
   */
  protected function indexedTitles(): array {
    $locator = $this->container->get('scolta.index_locator');
    $location = $locator->locate($this->config('scolta.settings')->get('pagefind.output_dir'));
    $this->assertNotNull($location, 'An index must exist');

    $titles = [];
    foreach ($locator->fragmentFiles($location) as $file) {
      $raw = gzdecode(file_get_contents($file));
      $payload = $raw === FALSE ? NULL : json_decode(substr($raw, (int) strpos($raw, '{')), TRUE);
      // A removed page is tombstoned: its fragment stays with an empty URL.
      if (is_array($payload) && ($payload['url'] ?? '') !== '') {
        $titles[] = $payload['meta']['title'];
      }
    }
    sort($titles);
    return $titles;
  }

}
