<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\scolta\Plugin\QueueWorker\ScoltaRebuildWorker;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\StatusReport;

/**
 * A segment that fails on a transient fault is retried, then given up on.
 *
 * The failure here is what a dropped redis connection looks like to the
 * worker: a report with success = FALSE and an error that is not
 * MEMORY_ABORT. Before this the marker went on the first such failure, and
 * every later `queue:run` tick found an empty queue and did nothing.
 *
 * @group scolta
 */
class ScoltaRebuildWorkerRetryKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  protected const MARKER = ['op' => 'resume'];

  protected const FAILURE_KEY = 'scolta.rebuild_segment_failures';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta', 'node', 'filter', 'field', 'text',
  ];

  /**
   * Real filesystem root for the index, outside vfsStream.
   */
  protected string $indexRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['scolta', 'field', 'node', 'filter']);

    $this->indexRoot = sys_get_temp_dir() . '/scolta-retry-' . uniqid();
    mkdir($this->indexRoot, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->indexRoot . '/output')
      ->set('pagefind.build_dir', $this->indexRoot . '/build')
      ->save();

    $this->createContentType(['type' => 'article']);
    Node::create([
      'type' => 'article',
      'title' => 'Article',
      'status' => 1,
      'body' => ['value' => str_repeat('Prose about aardvarks. ', 20), 'format' => 'plain_text'],
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->indexRoot)) {
      $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->indexRoot, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
      }
      rmdir($this->indexRoot);
    }
    parent::tearDown();
  }

  /**
   * The marker survives failing segments until the retry budget runs out.
   */
  public function testAFailedSegmentKeepsItsMarkerUntilRetriesAreExhausted(): void {
    // Tick 1: a fresh request whose segment dies. The marker stands so the
    // next tick has something to claim.
    $this->worker()->processItem(['type' => 'install']);
    $this->assertSame([self::MARKER], $this->queued(), 'The failed segment kept its marker');
    $this->assertSame(1, (int) \Drupal::state()->get(self::FAILURE_KEY));

    // Tick 2: the marker drives a retry, which fails again.
    $this->worker()->processItem(self::MARKER);
    $this->assertSame([self::MARKER], $this->queued(), 'A second failure still leaves the build claimable');
    $this->assertSame(2, (int) \Drupal::state()->get(self::FAILURE_KEY));

    // Tick 3 exhausts the budget: the build is given up on as before.
    $this->worker()->processItem(self::MARKER);
    $this->assertSame([], $this->queued(), 'The exhausted build deleted its marker');
    $this->assertNull(\Drupal::state()->get(self::FAILURE_KEY), 'The count is cleared with the marker');
  }

  /**
   * A succeeding segment clears the count a previous failure left behind.
   */
  public function testASuccessfulBuildClearsTheFailureCount(): void {
    $this->worker()->processItem(['type' => 'install']);
    $this->assertSame(1, (int) \Drupal::state()->get(self::FAILURE_KEY));

    $this->worker(fails: FALSE)->processItem(self::MARKER);

    $this->assertNull(\Drupal::state()->get(self::FAILURE_KEY));
    $this->assertSame([], $this->queued(), 'A completed build deletes its marker');
  }

  /**
   * A new request starts the budget over after an earlier build failed.
   */
  public function testANewRequestStartsTheFailureCountOver(): void {
    \Drupal::state()->set(self::FAILURE_KEY, 2);

    $this->worker()->processItem(['type' => 'install']);

    $this->assertSame(1, (int) \Drupal::state()->get(self::FAILURE_KEY), 'The stale count did not carry into a new build');
    $this->assertSame([self::MARKER], $this->queued());
  }

  /**
   * A worker whose segment fails on something that is not memory pressure.
   */
  protected function worker(bool $fails = TRUE): ScoltaRebuildWorker {
    \Drupal::state()->delete('scolta.rebuild_requested_at');
    $c = $this->container;
    $definition = $c->get('plugin.manager.queue_worker')->getDefinition('scolta_rebuild');

    $worker = new class(
      [],
      'scolta_rebuild',
      $definition,
      $c->get('lock'),
      $c->get('config.factory'),
      $c->get('state'),
      $c->get('cache_tags.invalidator'),
      $c->get('logger.channel.scolta'),
      $c->get('scolta.content_gatherer'),
      $c->get('queue'),
      $c->get('scolta.index_build_runner'),
    ) extends ScoltaRebuildWorker {

      /**
       * Whether the segment reports a transient failure instead of running.
       */
      public bool $fails = TRUE;

      /**
       * {@inheritdoc}
       */
      protected function runSegment(IndexBuildOrchestrator $orchestrator, BuildIntent $intent, array $entityTypes, array $cursors): StatusReport {
        $this->segmentRan = TRUE;
        if (!$this->fails) {
          return parent::runSegment($orchestrator, $intent, $entityTypes, $cursors);
        }
        return new StatusReport(
          version: '0',
          pagefindVersion: '0',
          resolvedIndexer: 'test',
          pagesProcessed: 2850,
          chunksWritten: 3,
          peakMemoryBytes: 0,
          memoryBudgetBytes: 0,
          durationSeconds: 1.0,
          outputDir: '',
          success: FALSE,
          error: 'read error on connection to redis:6379',
        );
      }

    };
    $worker->fails = $fails;
    return $worker;
  }

  /**
   * Every claimable payload, released again.
   *
   * @return array[]
   *   The payloads, in claim order.
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
