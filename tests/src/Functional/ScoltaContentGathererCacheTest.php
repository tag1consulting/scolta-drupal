<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\CachedContentReference;
use Tag1\Scolta\Index\TimestampManifest;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * Runtime coverage for the timestamp manifest cache decision in gather().
 *
 * ScoltaContentGatherer::gather() decides, per entity, whether to yield cheap
 * CachedContentReference objects out of the TimestampManifest or to reload the
 * entity and yield full ContentItems. A record written before 'metadata'
 * joined the manifest has to be treated as changed, so the entity reloads once
 * and its record is rewritten; that is what makes the upgrade self-healing
 * instead of requiring every site operator to run a forced build.
 *
 * The existing coverage in tests/src/ScoltaContentGathererTest.php parses the
 * gatherer's source text and asserts the guard is present. It would pass
 * against a guard that is syntactically there and behaviorally wrong, and the
 * distinction that matters here is invisible to a source grep:
 * array_key_exists() versus a falsy check. An item whose metadata is
 * legitimately an empty array must stay cached; under a falsy check it would
 * be reloaded on every build forever, silently converting every incremental
 * build into a full one.
 *
 * This test therefore drives the real gatherer against a real manifest and
 * asserts on what comes out of the generator: which entities arrive cached and
 * which arrive fully loaded.
 *
 * @group scolta
 */
class ScoltaContentGathererCacheTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'node', 'field', 'filter'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Entity keys of the three test nodes, in creation order.
   *
   * @var string[]
   */
  protected array $entityKeys = [];

  /**
   * The manifest the gatherer reads and writes under test.
   *
   * @var \Tag1\Scolta\Index\TimestampManifest
   */
  protected TimestampManifest $manifest;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    foreach (['One', 'Two', 'Three'] as $label) {
      $node = $this->drupalCreateNode([
        'type' => 'article',
        'title' => 'Cache node ' . $label,
        'body' => [
          'value' => 'Body copy for cache node ' . $label . ' that is comfortably longer than the minimum content length filter and is therefore indexable.',
          'format' => 'plain_text',
        ],
        'status' => 1,
      ]);
      $this->entityKeys[] = (string) $node->id();
    }

    // A manifest on a scratch directory under the test site's files. Nothing
    // here calls pruneAndSave(), so the manifest never touches disk: get() and
    // put() are enough to read a real record and write a modified one back.
    $filesDir = $this->container->get('file_system')->realpath('public://');
    $this->assertNotFalse($filesDir);
    $this->manifest = new TimestampManifest($filesDir . '/scolta-manifest-test', new FilesystemDriver());
  }

  /**
   * The write path stores a metadata key on every record it writes.
   */
  public function testFirstBuildLoadsEveryEntityAndStoresMetadata(): void {
    $result = $this->gatherWithManifest();

    $this->assertSame($this->sortedKeys($this->entityKeys), $result['loaded'],
      'With an empty manifest every entity must be fully loaded');
    $this->assertSame([], $result['cached']);

    foreach ($this->entityKeys as $key) {
      $entry = $this->manifest->get($key);
      $this->assertNotNull($entry, sprintf('Entity %s must have a manifest record after the first build', $key));
      $this->assertNotEmpty($entry['items'], sprintf('Entity %s must have at least one item record', $key));
      foreach ($entry['items'] as $itemData) {
        $this->assertArrayHasKey('metadata', $itemData,
          'Every item record the write path stores must carry the metadata key');
      }
    }
  }

  /**
   * Case A, the control: unchanged entities come back as cached references.
   *
   * Without this, the staleness cases below could pass for the wrong reason:
   * a gatherer that never caches anything satisfies "node 1 was reloaded" too.
   */
  public function testUnchangedEntitiesComeBackCached(): void {
    $this->populateManifest();

    $result = $this->gatherWithManifest();

    $this->assertSame([], $result['loaded'],
      'No entity changed between the two builds, so none may be reloaded');
    $this->assertSame($this->sortedKeys($this->entityKeys), $result['cached'],
      'Every unchanged entity must be yielded as a CachedContentReference');
  }

  /**
   * Case B: a record whose items predate metadata forces exactly one reload.
   *
   * Both halves are asserted. A guard that reloads everything unconditionally
   * also satisfies "node 1 was reloaded", and that guard would cost every site
   * its incremental builds.
   */
  public function testRecordWrittenBeforeMetadataIsReloaded(): void {
    $this->populateManifest();

    $this->rewriteRecord($this->entityKeys[0], function (array $itemData): array {
      unset($itemData['metadata']);
      return $itemData;
    });

    $result = $this->gatherWithManifest();

    $this->assertSame([$this->entityKeys[0]], $result['loaded'],
      'An entity whose stored items have no metadata key must be reloaded');
    $this->assertSame($this->sortedKeys([$this->entityKeys[1], $this->entityKeys[2]]), $result['cached'],
      'Entities with intact records must stay cached: the guard is per entity, not global');
  }

  /**
   * Case C: metadata present but empty is not stale.
   *
   * This is the case a falsy check gets wrong, and the assertion that makes
   * array_key_exists() load bearing rather than incidental. An item whose
   * metadata is legitimately an empty array must stay cached; treating it as
   * stale reloads it on every build for the life of the site.
   */
  public function testRecordWithExplicitlyEmptyMetadataStaysCached(): void {
    $this->populateManifest();

    $this->rewriteRecord($this->entityKeys[1], function (array $itemData): array {
      $itemData['metadata'] = [];
      return $itemData;
    });

    $result = $this->gatherWithManifest();

    $this->assertSame([], $result['loaded'],
      'An empty metadata array is a present value, not a stale record, so nothing may be reloaded');
    $this->assertSame($this->sortedKeys($this->entityKeys), $result['cached'],
      'The entity with an explicitly empty metadata array must still be yielded as a cached reference');
  }

  /**
   * Run a first build so the manifest holds a record per entity.
   */
  protected function populateManifest(): void {
    $result = $this->gatherWithManifest();
    $this->assertSame($this->sortedKeys($this->entityKeys), $result['loaded'],
      'Fixture precondition: the first build must load and record every entity');
  }

  /**
   * Rewrite every item record of one manifest entry, keeping its timestamp.
   *
   * @param string $entityKey
   *   The manifest key of the entry to rewrite.
   * @param callable $rewrite
   *   Receives one item record array, returns the replacement.
   */
  protected function rewriteRecord(string $entityKey, callable $rewrite): void {
    $entry = $this->manifest->get($entityKey);
    $this->assertNotNull($entry, sprintf('Entity %s must be in the manifest before it can be rewritten', $entityKey));

    $items = [];
    foreach ($entry['items'] as $itemData) {
      $items[] = $rewrite($itemData);
    }

    $this->manifest->put($entityKey, $entry['ts'], $items);
  }

  /**
   * Gather the corpus against the shared manifest and group what comes out.
   *
   * @return array{loaded: string[], cached: string[]}
   *   Sorted entity keys of the fully loaded items and of the cached
   *   references.
   */
  protected function gatherWithManifest(): array {
    $loaded = [];
    $cached = [];

    $items = $this->container->get('scolta.content_gatherer')
      ->gather('node', '', 'Cache Test Site', 0, $this->manifest, FALSE);

    foreach ($items as $item) {
      if ($item instanceof CachedContentReference) {
        $cached[] = $item->entityKey;
      }
      elseif ($item instanceof ContentItem) {
        $loaded[] = $item->id;
      }
      else {
        $this->fail('gather() yielded an unexpected type: ' . get_debug_type($item));
      }
    }

    return [
      'loaded' => $this->sortedKeys($loaded),
      'cached' => $this->sortedKeys($cached),
    ];
  }

  /**
   * Sort entity keys so comparisons do not depend on yield order.
   *
   * @param string[] $keys
   *   The keys to sort.
   *
   * @return string[]
   *   The sorted keys, reindexed from zero.
   */
  protected function sortedKeys(array $keys): array {
    sort($keys, SORT_STRING);
    return $keys;
  }

}
