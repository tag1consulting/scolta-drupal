<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\scolta\Service\ScoltaReindexer;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;

/**
 * ScoltaReindexer refreshes indexed output without an entity save.
 *
 * The case is stale output from changed alter logic: the content is fine, the
 * index's copy of it is not, and nothing about the entities has changed. The
 * test module's alter hook appends whatever marker state holds, which is the
 * smallest honest stand-in for editing an alter hook.
 *
 * The assertion that matters is testRefreshSurvivesTheNextFullBuild(): a
 * reindex that updates only the live index is silently undone by the next
 * full build, because that build reads the timestamp manifest, finds an
 * untouched `changed` timestamp still matching it, and serves the cached page
 * again.
 *
 * @group scolta
 */
class ScoltaReindexerKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta', 'scolta_reindex_test', 'node', 'filter', 'field', 'text', 'dblog',
  ];

  /**
   * Node IDs of the seeded corpus.
   *
   * @var int[]
   */
  protected array $nids = [];

  /**
   * The real directory holding the built index.
   */
  protected string $indexDir = '';

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

    // A real directory rather than the vfsStream public:// mount, which
    // scolta-php's FilesystemDriver rejects. See IncrementalQueueUpdateKernelTest.
    $this->indexDir = $this->container->get('file_system')->getTempDirectory()
      . '/scolta-reindex-test-' . uniqid();
    mkdir($this->indexDir, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->indexDir . '/output')
      ->set('pagefind.build_dir', $this->indexDir . '/build')
      ->save();

    $this->createContentType(['type' => 'article']);

    for ($i = 1; $i <= 5; $i++) {
      $node = Node::create([
        'type' => 'article',
        'title' => 'Seed article ' . $i,
        'body' => [
          'value' => 'Seeded body text for article number ' . $i . ' about zebras, written at length so the exporter minimum content length is comfortably cleared.',
          'format' => 'plain_text',
        ],
        'status' => 1,
      ]);
      $node->save();
      $this->nids[] = (int) $node->id();
    }

    // The index the reindex will be merged into.
    $this->runWorker(['type' => 'install']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Outside the vfsStream mount KernelTestBase tears down, so it is ours.
    if ($this->indexDir !== '' && is_dir($this->indexDir)) {
      $this->container->get('file_system')->deleteRecursive($this->indexDir);
    }
    parent::tearDown();
  }

  /**
   * A reindex reaches the live index without touching the entities.
   */
  public function testReindexRefreshesTheLiveIndex(): void {
    $changedBefore = $this->changedTimes();

    \Drupal::state()->set('scolta_reindex_test.marker', 'axolotl');
    $this->reindexer()->queue('node', $this->nids);
    $this->drainQueue();

    $this->assertStringContainsStringInArray('axolotl', $this->fragmentContents(),
      'The new alter output must be searchable after the queued reindex runs');
    $this->assertSame($changedBefore, $this->changedTimes(),
      'A reindex must not re-save the entities it refreshes');
  }

  /**
   * The refreshed content survives the next full build.
   *
   * Without a manifest rewrite the reindex is invisible to the full build:
   * `changed` never moved, so the manifest entry still looks fresh and the
   * build serves the cached page it recorded before the alter hook changed.
   */
  public function testRefreshSurvivesTheNextFullBuild(): void {
    \Drupal::state()->set('scolta_reindex_test.marker', 'axolotl');
    $this->reindexer()->queue('node', $this->nids);
    $this->drainQueue();
    $this->assertStringContainsStringInArray('axolotl', $this->fragmentContents());

    // A plain full rebuild, the kind an unrelated save or a deploy triggers.
    $this->runWorker(['type' => 'install']);

    $this->assertStringContainsStringInArray('axolotl', $this->fragmentContents(),
      'A full build must not replace the reindexed content with the stale manifest entry');
    $this->assertCount(count($this->nids), $this->livePages(),
      'Every page must survive the full build');
  }

  /**
   * Every queued payload is a forced, targeted reindex request.
   */
  public function testQueuedPayloadIsAForcedTargetedUpdate(): void {
    $queue = \Drupal::queue('scolta_rebuild');
    $result = $this->reindexer()->queue('node', [$this->nids[0]]);

    $this->assertSame(['entities' => 1, 'pages' => 1, 'skipped' => 0], $result);
    $this->assertSame(1, $queue->numberOfItems());
    $this->assertSame([
      'type' => 'reindex',
      'op' => 'update',
      'entity_type' => 'node',
      'entity_id' => (string) $this->nids[0],
      'item_ids' => ['node:' . $this->nids[0]],
      'force' => TRUE,
    ], $queue->claimItem()->data);

    // The debounce key stays untouched: this is not a content edit, so the
    // caller must not have to wait out a delay they did not cause.
    $this->assertNull(\Drupal::state()->get('scolta.rebuild_requested_at'));
  }

  /**
   * IDs that are missing or unpublished are counted, not queued.
   *
   * A repeated ID is not one of them: publishedIds() resolves the list
   * through an IN query, so a duplicate collapses rather than going missing.
   */
  public function testUnusableIdsAreReportedAsSkipped(): void {
    $unpublished = Node::create(['type' => 'article', 'title' => 'Draft', 'status' => 0]);
    $unpublished->save();
    // The save queued an incremental update of its own; only what the
    // reindexer queues is under test here.
    \Drupal::queue('scolta_rebuild')->deleteQueue();

    $result = $this->reindexer()->queue('node', [$this->nids[0], $this->nids[0], $unpublished->id(), 999999]);

    $this->assertSame(1, $result['entities']);
    $this->assertSame(2, $result['skipped']);
    $this->assertSame(1, \Drupal::queue('scolta_rebuild')->numberOfItems());
  }

  /**
   * A target set over the incremental threshold is refused, not queued.
   *
   * Over the threshold the worker falls back to a full rebuild, which reads
   * the manifest and would serve exactly the cached pages being refreshed.
   */
  public function testOversizedTargetSetIsRefused(): void {
    $this->config('scolta.settings')->set('incremental.max_changed_items', 2)->save();

    try {
      $this->reindexer()->queue('node', $this->nids);
      $this->fail('A target set over the threshold must be refused');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('scolta:build --force', $e->getMessage());
    }

    $this->assertSame(0, \Drupal::queue('scolta_rebuild')->numberOfItems(),
      'A refused reindex must queue nothing');
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  /**
   * The reindex service under test.
   */
  protected function reindexer(): ScoltaReindexer {
    return $this->container->get('scolta.reindexer');
  }

  /**
   * The `changed` timestamp of every seeded node.
   *
   * @return array<int, int>
   */
  protected function changedTimes(): array {
    $times = [];
    foreach (Node::loadMultiple($this->nids) as $node) {
      $times[(int) $node->id()] = $node->getChangedTime();
    }

    return $times;
  }

  /**
   * Run the worker on one queued item; it folds and deletes the rest itself.
   */
  protected function drainQueue(): void {
    $queue = \Drupal::queue('scolta_rebuild');
    $item = $queue->claimItem();
    $this->assertNotFalse($item, 'The reindex must have queued a rebuild request');
    $this->runWorker($item->data);
    $queue->deleteItem($item);
  }

  /**
   * Process one payload with the debounce disarmed.
   */
  protected function runWorker($data): void {
    \Drupal::state()->delete('scolta.rebuild_requested_at');
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance('scolta_rebuild')
      ->processItem($data);
  }

  /**
   * The decoded fragment payloads of the built index.
   *
   * @return array[]
   */
  protected function fragmentContents(): array {
    $locator = $this->container->get('scolta.index_locator');
    $location = $locator->locate($this->config('scolta.settings')->get('pagefind.output_dir'));
    $this->assertNotNull($location, 'An index must exist');

    $decoded = [];
    foreach ($locator->fragmentFiles($location) as $file) {
      $raw = gzdecode(file_get_contents($file));
      if ($raw === FALSE) {
        continue;
      }
      $start = strpos($raw, '{');
      if ($start === FALSE) {
        continue;
      }
      $payload = json_decode(substr($raw, $start), TRUE);
      if (is_array($payload)) {
        $decoded[] = $payload;
      }
    }

    return $decoded;
  }

  /**
   * The fragments that still represent a real page.
   *
   * @return array[]
   */
  protected function livePages(): array {
    return array_values(array_filter(
      $this->fragmentContents(),
      static fn(array $fragment): bool => ($fragment['url'] ?? '') !== ''
    ));
  }

  /**
   * Assert that some fragment's content contains a needle.
   */
  protected function assertStringContainsStringInArray(string $needle, array $fragments, string $message = 'No fragment contained the needle'): void {
    foreach ($fragments as $fragment) {
      if (str_contains($fragment['content'] ?? '', $needle)) {
        $this->assertTrue(TRUE);
        return;
      }
    }
    $this->fail($message . ' (no fragment contained "' . $needle . '")');
  }

}
