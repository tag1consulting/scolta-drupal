<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;

/**
 * Coverage for the gatherer's keyset pagination.
 *
 * gather() walked the corpus with LIMIT n OFFSET m, which makes the database
 * skip and discard m rows before returning any. With a batch of 10 the last
 * batches of a six-figure corpus each discarded ~100k rows. The cursor is now
 * expressed as "IDs greater than the last one seen", which is O(batch) at any
 * depth.
 *
 * The risk a cursor rewrite carries is an off-by-one at a batch boundary: a
 * row silently skipped or yielded twice. The existing end-to-end test runs a
 * 3-page corpus, which cannot see a boundary at all, so these run a corpus
 * many batches deep and check the walk exactly.
 *
 * @group scolta
 */
class KeysetPaginationFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'node', 'filter', 'field'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Published node IDs, ascending.
   *
   * @var int[]
   */
  protected array $publishedNids = [];

  /**
   * Unpublished node IDs.
   *
   * @var int[]
   */
  protected array $unpublishedNids = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);

    // 137 published articles: not a multiple of the batch size of 10, so the
    // final partial batch is exercised too. Interleaved unpublished nodes and
    // a second bundle make the ID sequence non-contiguous, which is what an
    // off-by-one in the cursor would hide behind on a dense sequence.
    for ($i = 1; $i <= 137; $i++) {
      $node = Node::create([
        'type' => 'article',
        'title' => 'Article ' . $i,
        'body' => ['value' => 'Body of article ' . $i, 'format' => 'plain_text'],
        'status' => 1,
      ]);
      $node->save();
      $this->publishedNids[] = (int) $node->id();

      if ($i % 7 === 0) {
        $hidden = Node::create([
          'type' => 'article',
          'title' => 'Hidden ' . $i,
          'body' => ['value' => 'Body of hidden ' . $i, 'format' => 'plain_text'],
          'status' => 0,
        ]);
        $hidden->save();
        $this->unpublishedNids[] = (int) $hidden->id();
      }

      if ($i % 11 === 0) {
        $other = Node::create([
          'type' => 'page',
          'title' => 'Page ' . $i,
          'body' => ['value' => 'Body of page ' . $i, 'format' => 'plain_text'],
          'status' => 1,
        ]);
        $other->save();
      }
    }

    sort($this->publishedNids);
  }

  /**
   * The walk yields every published row exactly once, in ascending ID order.
   */
  public function testWalkCoversEveryRowExactlyOnce(): void {
    $ids = $this->gatheredItemIds('article');

    $this->assertSame(
      array_map('strval', $this->publishedNids),
      $ids,
      'Keyset pagination must yield every published node of the bundle exactly once, ascending, with no row skipped or repeated across a batch boundary'
    );
  }

  /**
   * An unfiltered walk covers every published node of every bundle.
   *
   * Also the only place unpublished-exclusion and bundle-scoping are still
   * observable independently: testWalkCoversEveryRowExactlyOnce's exact-list
   * assertion against the article bundle already implies both (an unpublished
   * or cross-bundle row appearing there would break that equality outright),
   * so a dedicated test for either would be redundant.
   */
  public function testUnfilteredWalkCoversAllBundles(): void {
    $expected = array_values(
      $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->sort('nid', 'ASC')
        ->execute()
    );

    $this->assertSame(array_map('strval', $expected), $this->gatheredItemIds(''),
      'An unfiltered walk must cover every published node, ascending');
  }

  /**
   * The resume boundary is an entity ID, and it is inclusive.
   *
   * It used to be a row offset fed from the build manifest's pages_processed,
   * which counts pages while this walk counts entities — one page per
   * translation, so the two disagree by the translation factor and the cursor
   * landed on the wrong row. It is now the entity the page-table ledger
   * records the build as having reached.
   *
   * Inclusive because that entity may have had only some of its translations
   * committed before the memory limit hit; the orchestrator drops the ones it
   * already holds. Yielding it again costs a re-index, whereas skipping it
   * loses a page silently, so the boundary errs toward the recoverable side.
   *
   * The IDs here are deliberately non-contiguous — unpublished nodes and a
   * second bundle are interleaved — so a cursor that quietly assumed a dense
   * sequence would land off the boundary rather than on it.
   */
  public function testResumingFromAnEntityIdYieldsThatRowAndEveryRowAfterIt(): void {
    $all = $this->gatheredItemIds('article');

    foreach ([1, 10, 11, 57, 136] as $position) {
      $boundary = $all[$position];

      $this->assertSame(
        array_slice($all, $position),
        $this->gatheredItemIds('article', $boundary),
        'Resuming from entity ' . $boundary . ' must yield that row and every row after it'
      );
    }
  }

  /**
   * A resume boundary below every ID in the corpus changes nothing.
   */
  public function testAResumeBoundaryBelowTheCorpusYieldsEverything(): void {
    $this->assertSame(
      $this->gatheredItemIds('article'),
      $this->gatheredItemIds('article', 1),
      'Resuming from an ID at or below the first row must cover the whole corpus'
    );
  }

  /**
   * Gather a bundle and return the content item IDs in yielded order.
   *
   * @param string $bundle
   *   The bundle to walk.
   * @param int|string|null $resumeFromId
   *   Entity ID to restart the walk at, inclusive, or NULL for the whole walk.
   *
   * @return string[]
   */
  protected function gatheredItemIds(string $bundle, int|string|NULL $resumeFromId = NULL): array {
    $ids = [];
    $gatherer = $this->container->get('scolta.content_gatherer');
    foreach ($gatherer->gather('node', $bundle, 'Keyset Site', $resumeFromId) as $item) {
      $ids[] = (string) $item->id;
    }

    return $ids;
  }

}
