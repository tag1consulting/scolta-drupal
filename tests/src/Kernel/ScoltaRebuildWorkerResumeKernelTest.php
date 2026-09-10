<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\scolta\Plugin\QueueWorker\ScoltaRebuildWorker;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\ResumeChainPolicy;

/**
 * The queue worker finishes a build that outgrows one cron run.
 *
 * Every orchestrator the worker builds here carries a memory-pressure probe
 * that fires once, so each run yields after its first chunk exactly as a
 * process at its memory limit would. Each run gets a new worker instance, as
 * each cron run does, so nothing but the state directory and the queue
 * carries the build between them.
 *
 * @group scolta
 */
class ScoltaRebuildWorkerResumeKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta', 'node', 'filter', 'field', 'text', 'dblog',
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
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['scolta', 'field', 'node', 'filter']);

    // A real directory: scolta-php's FilesystemDriver rejects vfs:// URIs.
    $this->indexRoot = sys_get_temp_dir() . '/scolta-resume-' . uniqid();
    mkdir($this->indexRoot, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->indexRoot . '/output')
      ->set('pagefind.build_dir', $this->indexRoot . '/build')
      // Three pages per chunk: ten nodes need several segments.
      ->set('memory_budget.chunk_size', 3)
      ->save();

    $this->createContentType(['type' => 'article']);
    for ($i = 1; $i <= 10; $i++) {
      Node::create([
        'type' => 'article',
        'title' => 'Article ' . $i,
        'status' => 1,
        'body' => ['value' => str_repeat("Node prose about aardvarks {$i}. ", 30), 'format' => 'plain_text'],
      ])->save();
    }
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
   * A build yields, hands itself to the next runs via a marker, and completes.
   */
  public function testABuildCompletesAcrossCronRunsFromDiskAlone(): void {
    $queue = \Drupal::queue('scolta_rebuild');
    $queue->createItem(['op' => 'update', 'entity_type' => 'node', 'entity_id' => 1, 'item_ids' => ['node:1']]);

    // Run 1: a full-rebuild request starts a fresh build that yields.
    $this->worker()->processItem(['type' => 'install']);

    $this->assertTrue(ResumeChainPolicy::resumable($this->buildState()), 'The yield left a resumable build on disk');
    $this->assertCount(0, $this->fragments(), 'Nothing is published until the build completes');
    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    $this->assertSame(['op' => 'resume'], $item->data, 'The covered request is gone; only the marker remains');
    $this->assertFalse($queue->claimItem(), 'The request folded into the fresh build is deleted');

    // Runs 2..n: each one resumes, yields, and releases the marker.
    $runs = 1;
    while ($runs < 10) {
      $runs++;
      try {
        $this->worker()->processItem($item->data);
        break;
      }
      catch (SuspendQueueException $e) {
        $this->assertTrue(ResumeChainPolicy::resumable($this->buildState()), 'A yield keeps the build resumable');
        $queue->releaseItem($item);
        $item = $queue->claimItem();
        $this->assertNotFalse($item);
      }
    }
    $queue->deleteItem($item);

    $this->assertGreaterThan(2, $runs, 'The probe must have forced more than one resume');
    $this->assertCount(10, $this->fragments(), 'Every page reached the published index');
    $this->assertFalse(ResumeChainPolicy::resumable($this->buildState()), 'A published build leaves nothing to resume');
    $this->assertFalse($queue->claimItem(), 'The marker was the last item');
  }

  /**
   * A request arriving mid-build is requeued once the build completes.
   */
  public function testARequestArrivingMidBuildIsRequeuedAfterTheFinalSegment(): void {
    $this->worker()->processItem(['type' => 'install']);
    $this->assertTrue(ResumeChainPolicy::resumable($this->buildState()));

    // An edit made after the build started may name an entity the walk has
    // already passed, so it cannot be treated as covered.
    $edit = ['op' => 'update', 'entity_type' => 'node', 'entity_id' => 1, 'item_ids' => ['node:1']];
    $requeued = FALSE;
    for ($run = 0; $run < 10 && !$requeued; $run++) {
      try {
        $this->worker()->processItem($edit);
        $this->fail('A resumed segment never returns normally for a request it did not cover');
      }
      catch (SuspendQueueException $e) {
        // Yielded again; cron would release the edit and try next run.
      }
      catch (RequeueException $e) {
        $requeued = TRUE;
      }
    }

    $this->assertTrue($requeued, 'The final segment must hand the edit back to the queue');
    $this->assertCount(10, $this->fragments());
    $this->assertFalse(ResumeChainPolicy::resumable($this->buildState()));

    // With no build to resume, a stale marker is a no-op and the edit takes
    // the incremental path against the finished index.
    $this->worker()->processItem(['op' => 'resume']);
    $this->worker()->processItem($edit);
    $this->assertCount(10, $this->fragments());
  }

  /**
   * A worker whose orchestrators yield once, built with the real container.
   */
  protected function worker(): ScoltaRebuildWorker {
    \Drupal::state()->delete('scolta.rebuild_requested_at');
    $c = $this->container;
    $definition = $c->get('plugin.manager.queue_worker')->getDefinition('scolta_rebuild');

    return new class(
      [],
      'scolta_rebuild',
      $definition,
      $c->get('lock'),
      $c->get('config.factory'),
      $c->get('file_system'),
      $c->get('stream_wrapper_manager'),
      $c->get('state'),
      $c->get('cache_tags.invalidator'),
      $c->get('logger.channel.scolta'),
      $c->get('scolta.content_gatherer'),
      $c->get('queue'),
    ) extends ScoltaRebuildWorker {

      /**
       * {@inheritdoc}
       */
      protected function createOrchestrator(string $stateDir, string $outputDir, string $language): IndexBuildOrchestrator {
        $fired = FALSE;
        return new IndexBuildOrchestrator($stateDir, $outputDir, NULL, $language, memoryPressureProbe: function () use (&$fired): bool {
          if ($fired) {
            return FALSE;
          }
          return $fired = TRUE;
        });
      }

    };
  }

  /**
   * The build state the worker reads and writes.
   */
  protected function buildState(): BuildState {
    return new BuildState($this->indexRoot . '/build');
  }

  /**
   * Fragment files of the published index.
   *
   * @return string[]
   *   The fragment paths.
   */
  protected function fragments(): array {
    return glob($this->indexRoot . '/output/pagefind/fragment/*.pf_fragment') ?: [];
  }

}
