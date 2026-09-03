<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;

/**
 * End-to-end coverage of the queue worker's incremental update path.
 *
 * Every assertion here is about what ends up in the index after a real edit
 * travels through the real queue, because the two defects this covers were
 * both invisible to the source-text tests that made up most of the suite:
 *
 *  - there was no hook_entity_delete() at all, so a deleted node kept being
 *    served until some unrelated save triggered a full rebuild;
 *  - the worker drained the whole queue after a build, including the requests
 *    that arrived while the build was running, so those edits were dropped
 *    without ever being indexed.
 *
 * The corpus is deliberately larger than one gather batch so an edit has to
 * be found rather than stumbled over. No HTTP request is involved anywhere
 * — content is created and edited through the entity API and the queue
 * worker is invoked directly — so this needs only a real container, not
 * BrowserTestBase.
 *
 * @group scolta
 */
class IncrementalQueueUpdateKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta', 'search_api', 'node', 'filter', 'field', 'text', 'dblog', 'language',
  ];

  /**
   * Node IDs of the seeded corpus, in creation order.
   *
   * @var int[]
   */
  protected array $nids = [];

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

    // KernelTestBase mounts public:// on a vfsStream virtual filesystem, and
    // its own realpath() is a vfs:// URI — still stream-wrapper syntax, which
    // scolta-php's FilesystemDriver rejects outright (it needs a real file
    // system for the index it builds and reads back). Pointing the index at
    // a real temp directory instead sidesteps vfsStream entirely; the
    // production resolveDir() already treats any path with no "://" as
    // already-resolved, so this is the same path plain-path config a real
    // site can use.
    $realDir = sys_get_temp_dir() . '/scolta-kernel-test-' . uniqid();
    mkdir($realDir, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $realDir . '/output')
      ->set('pagefind.build_dir', $realDir . '/build')
      ->save();

    $this->createContentType(['type' => 'article']);

    // 25 nodes: more than two gather batches of 10, so pagination is exercised
    // rather than short-circuited.
    for ($i = 1; $i <= 25; $i++) {
      $node = Node::create([
        'type' => 'article',
        'title' => 'Seed article ' . $i,
        'body' => [
          'value' => 'Seeded body text for article number ' . $i . ' about zebras.',
          'format' => 'plain_text',
        ],
        'status' => 1,
      ]);
      $node->save();
      $this->nids[] = (int) $node->id();
    }

    // A full build first: incremental updates apply to an existing index with
    // a page-table ledger, they do not create one.
    $this->runWorker(['type' => 'install']);
  }

  /**
   * An edit reaches the index through the queue.
   */
  public function testEditReachesTheIndex(): void {
    $before = count($this->livePages());

    $node = Node::load($this->nids[7]);
    $node->set('body', [
      'value' => 'Rewritten body mentioning pangolins instead of the original subject, with enough prose to clear the exporter minimum content length.',
      'format' => 'plain_text',
    ]);
    $node->save();

    $this->drainOneQueueItem();

    $contents = $this->fragmentContents();
    $this->assertStringContainsStringInArray('pangolins', $contents,
      'The edited body must be searchable after the queue worker runs');
    $this->assertStringNotContainsStringInArray('article number 8 about zebras', $contents,
      'The superseded body must not survive in the index');
    $this->assertCount($before, $this->livePages(),
      'An edit replaces a page, it does not add one');
  }

  /**
   * An append reaches the index without dropping the original text.
   */
  public function testAppendReachesTheIndex(): void {
    $node = Node::load($this->nids[3]);
    $node->set('body', [
      'value' => 'Seeded body text for article number 4 about zebras, long enough to clear the exporter minimum content length. Appended sentence about okapi.',
      'format' => 'plain_text',
    ]);
    $node->save();

    $this->drainOneQueueItem();

    $contents = $this->fragmentContents();
    $this->assertStringContainsStringInArray('okapi', $contents,
      'Appended text must be indexed');
    $this->assertStringContainsStringInArray('article number 4 about zebras', $contents,
      'An append must not drop the text it was appended to');
  }

  /**
   * A delete removes the page from the index.
   *
   * The gap this closes: before hook_entity_delete() existed, nothing was
   * enqueued on a delete at all, so this page stayed in the index until an
   * unrelated save happened to trigger a full rebuild.
   */
  public function testDeleteRemovesThePage(): void {
    $before = count($this->livePages());

    $node = Node::load($this->nids[11]);
    $url = $node->toUrl()->toString();
    $node->delete();

    $this->drainOneQueueItem();

    $urls = array_column($this->livePages(), 'url');
    $this->assertNotContains($url, $urls,
      'A deleted node must not remain in the index');
    // A removed page tombstones rather than renumbering the page table, so its
    // fragment file survives as an empty placeholder and the file count is
    // unchanged. What must drop is the number of pages that still carry a URL.
    $this->assertCount($before - 1, $this->livePages(),
      'Deleting one node must remove exactly one live page');
  }

  /**
   * An unpublish removes the page, exactly as a delete does.
   *
   * An unpublish arrives as an ordinary update. Staging its content as an
   * upsert would keep a hidden node searchable.
   */
  public function testUnpublishRemovesThePage(): void {
    $before = count($this->livePages());

    $node = Node::load($this->nids[5]);
    $url = $node->toUrl()->toString();
    $node->setUnpublished();
    $node->save();

    $this->drainOneQueueItem();

    $urls = array_column($this->livePages(), 'url');
    $this->assertNotContains($url, $urls,
      'An unpublished node must not remain searchable');
    $this->assertCount($before - 1, $this->livePages(),
      'Unpublishing one node must remove exactly one live page');
  }

  /**
   * Removing a translation removes its page.
   *
   * The page ID for a removed translation is absent from the saved entity's
   * translation list, so deriving the payload from the post-save entity alone
   * never mentions it: it is not staged as an upsert, so it is never missing
   * from the gather either, so nothing ever removes it and the orphaned
   * translation page stays searchable until a full rebuild. The payload
   * therefore unions the pre-save item IDs.
   */
  public function testRemovingATranslationRemovesItsPage(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Translated article',
      'body' => [
        'value' => 'English body copy that is comfortably long enough to clear the minimum content length filter.',
        'format' => 'plain_text',
      ],
      'status' => 1,
    ]);
    $node->addTranslation('es', [
      'title' => 'Articulo traducido',
      'body' => [
        'value' => 'Cuerpo en español que es lo bastante largo para superar el filtro de longitud minima y ser indexado.',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->drainOneQueueItem();

    $this->assertStringContainsStringInArray('Cuerpo en español', $this->fragmentContents(),
      'The translation must be indexed as its own page before it can be removed');
    $before = count($this->livePages());

    $node = Node::load($node->id());
    $node->removeTranslation('es');
    $node->save();

    $this->drainOneQueueItem();

    $this->assertStringNotContainsStringInArray('Cuerpo en español', $this->fragmentContents(),
      'A removed translation must not stay searchable');
    $this->assertStringContainsStringInArray('English body copy', $this->fragmentContents(),
      'Removing a translation must not disturb the remaining one');
    $this->assertCount($before - 1, $this->livePages(),
      'Removing one translation must remove exactly one live page');
  }

  /**
   * The worker deletes only the queue items it actually covered.
   *
   * This is the concurrency contract. The old drainQueue() claimed and deleted
   * every item in the queue after a build, so an edit saved while the build
   * was running had its request deleted without its content ever being
   * gathered — a silent, permanent loss. Items are now claimed up front and
   * only those handles are deleted, so anything arriving later survives.
   */
  public function testQueueItemsArrivingAfterTheClaimSurvive(): void {
    $queue = \Drupal::queue('scolta_rebuild');

    // Two edits enqueue two requests.
    $first = Node::load($this->nids[1]);
    $first->set('body', [
      'value' => 'First edit about narwhals, written at length so the exporter minimum content length is comfortably cleared.',
      'format' => 'plain_text',
    ]);
    $first->save();

    $covered = $queue->numberOfItems();
    $this->assertGreaterThan(0, $covered);

    $worker = $this->workerWithoutDebounce();

    // Claim and fold everything currently queued, exactly as processItem does.
    $claimed = [];
    $changeSet = $worker->exposedCollectChangeSet([
      'type' => 'auto',
      'op' => 'update',
      'entity_type' => 'node',
      'entity_id' => $this->nids[1],
      'item_ids' => [(string) $this->nids[1]],
    ], $claimed);
    $this->assertTrue($changeSet['targeted']);
    $this->assertCount($covered, $claimed);

    // An edit lands *after* the claim — this is the mid-build edit.
    $second = Node::load($this->nids[2]);
    $second->set('body', [
      'value' => 'Second edit about quokkas, written at length so the exporter minimum content length is comfortably cleared.',
      'format' => 'plain_text',
    ]);
    $second->save();

    // The run completes and deletes only what it claimed.
    $worker->exposedDeleteClaimed($claimed);

    $this->assertSame(1, $queue->numberOfItems(),
      'The request that arrived after the claim must survive the run that did not cover it');

    // And the next run applies it, so the edit is not merely retained but
    // actually reaches the index.
    $this->drainOneQueueItem();
    $this->assertStringContainsStringInArray('quokkas', $this->fragmentContents(),
      'The surviving request must be applied by the next run');
  }

  /**
   * A targeted edit takes the incremental path, not a silent full rebuild.
   *
   * Without this the whole change set could regress to "correct but still a
   * full build" and every content assertion above would still pass.
   */
  public function testTargetedEditUsesTheIncrementalPath(): void {
    $node = Node::load($this->nids[9]);
    $node->set('body', [
      'value' => 'Body rewritten for the routing check, mentioning tapirs, and long enough to clear the exporter minimum content length.',
      'format' => 'plain_text',
    ]);
    $node->save();

    $this->drainOneQueueItem();

    $this->assertTrue(
      $this->loggedMessageMatching('%updated incrementally%'),
      'A single-node edit must be applied incrementally rather than by rebuilding the whole corpus'
    );
  }

  /**
   * A change set larger than the threshold falls back to a full rebuild.
   */
  public function testLargeChangeSetFallsBackToFullRebuild(): void {
    $this->config('scolta.settings')->set('incremental.max_changed_items', 2)->save();

    foreach ([13, 14, 15, 16] as $index) {
      $node = Node::load($this->nids[$index]);
      $node->set('body', [
        'value' => 'Bulk edit ' . $index . ' about lemurs, written at length so the exporter minimum content length is comfortably cleared.',
        'format' => 'plain_text',
      ]);
      // The full-build path asks the timestamp manifest what to re-read, and
      // the whole test process shares one frozen REQUEST_TIME, so setUp()'s
      // build and these edits would record the identical `changed` value and
      // every edit would look unchanged. Stamping the edit later is what a
      // real save does when it happens after a build; without it the test
      // measures the frozen clock rather than the fallback.
      $node->setChangedTime($node->getChangedTime() + 60);
      $node->save();
    }

    $this->drainOneQueueItem();

    $this->assertTrue(
      $this->loggedMessageMatching('%exceeds the incremental threshold%'),
      'A change set over the configured threshold must fall back to a full rebuild, loudly'
    );
    $this->assertStringContainsStringInArray('lemurs', $this->fragmentContents(),
      'The fallback must still index the changes');
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  /**
   * Run the queue worker on one claimed item, the way cron does.
   */
  protected function drainOneQueueItem(): void {
    $queue = \Drupal::queue('scolta_rebuild');
    $item = $queue->claimItem();
    $this->assertNotFalse($item, 'The save must have enqueued a rebuild request');
    $this->runWorker($item->data);
    $queue->deleteItem($item);
  }

  /**
   * Process one payload with the debounce disarmed.
   */
  protected function runWorker($data): void {
    // No recorded content change → the debounce must not delay the build.
    \Drupal::state()->delete('scolta.rebuild_requested_at');
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance('scolta_rebuild')
      ->processItem($data);
  }

  /**
   * A worker subclass exposing the protected change-set helpers.
   */
  protected function workerWithoutDebounce(): object {
    \Drupal::state()->delete('scolta.rebuild_requested_at');
    $worker = $this->container->get('plugin.manager.queue_worker')
      ->createInstance('scolta_rebuild');

    return new class($worker) {

      public function __construct(private readonly object $worker) {}

      public function exposedCollectChangeSet($data, array &$claimed): array {
        $method = new \ReflectionMethod($this->worker, 'collectChangeSet');

        return $method->invokeArgs($this->worker, [$data, &$claimed]);
      }

      public function exposedDeleteClaimed(array $claimed): void {
        $method = new \ReflectionMethod($this->worker, 'deleteClaimed');
        $method->invoke($this->worker, $claimed);
      }

    };
  }

  /**
   * The fragment file paths of the built index.
   *
   * @return string[]
   */
  protected function fragments(): array {
    $locator = $this->container->get('scolta.index_locator');
    $uri = $this->config('scolta.settings')->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    // setUp() configures a real path with no stream-wrapper prefix (see the
    // comment there); resolve through the stream wrapper manager only if the
    // configured value actually looks like a stream URI.
    $dir = str_contains($uri, '://')
      ? $this->container->get('stream_wrapper_manager')->getViaUri($uri)->realpath()
      : $uri;
    $location = $locator->locate($dir);
    $this->assertNotNull($location, 'An index must exist');

    return $locator->fragmentFiles($location);
  }

  /**
   * The decoded fragment payloads of the built index.
   *
   * Fragments are gzip-compressed and carry a `pagefind_dcd` marker before the
   * JSON body.
   *
   * @return array[]
   */
  protected function fragmentContents(): array {
    $decoded = [];
    foreach ($this->fragments() as $file) {
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
   * A removed page tombstones instead of renumbering the page table, so its
   * fragment file stays on disk with an empty URL and empty content. Counting
   * files would therefore never see a delete; counting pages that still carry
   * a URL does.
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
   * Whether the module logged a message matching a LIKE pattern.
   */
  protected function loggedMessageMatching(string $pattern): bool {
    $count = \Drupal::database()->select('watchdog', 'w')
      ->condition('w.type', 'scolta')
      ->condition('w.message', $pattern, 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();

    return (int) $count > 0;
  }

  /**
   * Assert that some fragment's content contains a needle.
   */
  protected function assertStringContainsStringInArray(string $needle, array $fragments, string $message): void {
    foreach ($fragments as $fragment) {
      if (str_contains($fragment['content'] ?? '', $needle)) {
        $this->assertTrue(TRUE);
        return;
      }
    }
    $this->fail($message . ' (no fragment contained "' . $needle . '")');
  }

  /**
   * Assert that no fragment's content contains a needle.
   */
  protected function assertStringNotContainsStringInArray(string $needle, array $fragments, string $message): void {
    foreach ($fragments as $fragment) {
      if (str_contains($fragment['content'] ?? '', $needle)) {
        $this->fail($message . ' (a fragment still contained "' . $needle . '")');
      }
    }
    $this->assertTrue(TRUE);
  }

}
