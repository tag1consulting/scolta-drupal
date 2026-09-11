<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\Core\Queue\RequeueException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\scolta\Plugin\QueueWorker\ScoltaRebuildWorker;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\ResumeChainPolicy;
use Tag1\Scolta\Index\StatusReport;

/**
 * The queue worker finishes a build that outgrows one `queue:run` tick.
 *
 * Every orchestrator the worker builds here carries a memory-pressure probe
 * that fires once, so each run yields after its first chunk exactly as a
 * process at its memory limit would, and the in-process chain is disabled
 * (it spawns drush, which would run against the wrong database), so the
 * marker carries the build from tick to tick as it does on a host without
 * drush. Each run gets a new worker instance, as each tick does, so nothing
 * but the state directory and the queue carries the build between them.
 *
 * @group scolta
 */
class ScoltaRebuildWorkerResumeKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  protected const MARKER = ['op' => 'resume'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta', 'search_api', 'node', 'filter', 'field', 'text', 'dblog',
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
   * A build yields, hands itself to the next ticks via a marker, and completes.
   */
  public function testABuildCompletesAcrossTicksFromDiskAlone(): void {
    $queue = \Drupal::queue('scolta_rebuild');
    $queue->createItem(['op' => 'update', 'entity_type' => 'node', 'entity_id' => 1, 'item_ids' => ['node:1']]);

    // Tick 1: a full-rebuild request starts a fresh build that yields. The
    // runner deletes the payload it held; the marker stands for the build.
    $this->worker()->processItem(['type' => 'install']);

    $this->assertTrue(ResumeChainPolicy::resumable($this->buildState()), 'The yield left a resumable build on disk');
    $this->assertCount(0, $this->fragments(), 'Nothing is published until the build completes');
    $this->assertSame([self::MARKER], $this->queued(), 'The covered request is gone; only the marker remains');

    // Ticks 2..n: each one resumes, yields, and leaves one marker behind.
    $runs = 1;
    while (ResumeChainPolicy::resumable($this->buildState()) && $runs < 10) {
      $runs++;
      $item = $queue->claimItem();
      $this->assertNotFalse($item);
      $this->worker()->processItem($item->data);
      $queue->deleteItem($item);
    }

    $this->assertGreaterThan(2, $runs, 'The probe must have forced more than one resume');
    $this->assertCount(10, $this->fragments(), 'Every page reached the published index');
    $this->assertFalse(ResumeChainPolicy::resumable($this->buildState()), 'A published build leaves nothing to resume');
    $this->assertSame([], $this->queued(), 'The completed build deleted its marker');
  }

  /**
   * A request arriving mid-build is requeued after every segment it sees.
   */
  public function testARequestArrivingMidBuildIsRequeuedUntilTheBuildCompletes(): void {
    $this->worker()->processItem(['type' => 'install']);
    $this->assertTrue(ResumeChainPolicy::resumable($this->buildState()));

    // An edit made after the build started may name an entity the walk has
    // already passed, so it cannot be treated as covered.
    $edit = ['op' => 'update', 'entity_type' => 'node', 'entity_id' => 1, 'item_ids' => ['node:1']];
    $requeues = 0;
    for ($run = 0; $run < 10 && ResumeChainPolicy::resumable($this->buildState()); $run++) {
      try {
        $this->worker()->processItem($edit);
        $this->fail('A resumed segment never returns normally for a request it did not cover');
      }
      catch (RequeueException $e) {
        $requeues++;
      }
    }

    $this->assertGreaterThan(1, $requeues, 'The edit must be handed back after each segment, the final one included');
    $this->assertCount(10, $this->fragments());
    $this->assertFalse(ResumeChainPolicy::resumable($this->buildState()));

    // The edit, back in the queue, takes the incremental path against the
    // finished index.
    $this->worker()->processItem($edit);
    $this->assertCount(10, $this->fragments());
  }

  /**
   * A process killed mid-segment leaves a claimable marker for the next tick.
   *
   * The lease on the payload the runner holds does not matter: the marker
   * was enqueued before the segment ran, so it is the request the next tick
   * acts on, and duplicates are drained down to one.
   */
  public function testAKilledSegmentLeavesOneClaimableMarker(): void {
    $queue = \Drupal::queue('scolta_rebuild');
    $queue->createItem(['op' => 'update', 'entity_type' => 'node', 'entity_id' => 2, 'item_ids' => ['node:2']]);
    $queue->createItem(self::MARKER);
    $queue->createItem(self::MARKER);

    try {
      $this->worker(killed: TRUE)->processItem(['type' => 'install']);
      $this->fail('The simulated kill must propagate');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('killed', $e->getMessage());
    }

    $this->assertSame([self::MARKER], $this->queued(), 'Exactly one marker, and no folded-in request, survives the kill');
    $this->assertCount(0, $this->fragments());

    // The marker alone drives the build to completion on later ticks.
    for ($run = 0; $run < 10; $run++) {
      $item = $queue->claimItem();
      $this->assertNotFalse($item, 'A marker is claimable while the build is unfinished');
      $this->worker()->processItem($item->data);
      $queue->deleteItem($item);
      if (!ResumeChainPolicy::resumable($this->buildState()) && $this->fragments() !== []) {
        break;
      }
    }

    $this->assertCount(10, $this->fragments());
    $this->assertSame([], $this->queued());
  }

  /**
   * A worker whose orchestrators yield once, built with the real container.
   *
   * @param bool $killed
   *   Whether its segment dies before the orchestrator runs, as a SIGKILL
   *   during the walk would leave it: marker enqueued, nothing on disk.
   */
  protected function worker(bool $killed = FALSE): ScoltaRebuildWorker {
    \Drupal::state()->delete('scolta.rebuild_requested_at');
    $c = $this->container;
    $definition = $c->get('plugin.manager.queue_worker')->getDefinition('scolta_rebuild');

    $worker = new class(
      [],
      'scolta_rebuild',
      $definition,
      $c->get('lock'),
      $c->get('config.factory'),
      $c->get('entity_type.manager'),
      $c->get('state'),
      $c->get('cache_tags.invalidator'),
      $c->get('logger.channel.scolta'),
      $c->get('scolta.content_gatherer'),
      $c->get('queue'),
      $c->get('scolta.index_build_runner'),
    ) extends ScoltaRebuildWorker {

      /**
       * Whether runSegment() dies instead of running.
       */
      public bool $killed = FALSE;

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

      /**
       * {@inheritdoc}
       */
      protected function runSegment(IndexBuildOrchestrator $orchestrator, BuildIntent $intent, array $entityTypes, array $cursors, string $outputDir): StatusReport {
        if ($this->killed) {
          throw new \RuntimeException('killed');
        }
        return parent::runSegment($orchestrator, $intent, $entityTypes, $cursors, $outputDir);
      }

      /**
       * {@inheritdoc}
       */
      protected function chain(BuildState $buildState, StatusReport $yielded): ?StatusReport {
        return NULL;
      }

    };
    $worker->killed = $killed;
    return $worker;
  }

  /**
   * The build state the worker reads and writes.
   */
  protected function buildState(): BuildState {
    return new BuildState($this->indexRoot . '/build');
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
