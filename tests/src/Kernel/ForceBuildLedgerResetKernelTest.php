<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\scolta\Commands\ScoltaCommands;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drush\Log\DrushLoggerManager;
use Symfony\Component\Console\Output\NullOutput;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * `drush scolta:build --force` rebuilds the page table too.
 *
 * Observed after an in-place upgrade from 1.4.0: `--force` logged "38 pages
 * indexed" and published a pagefind-entry.json with page_count 56. The ledger
 * survived the forced build, so every ordinal the old index used and the new
 * build did not yield became a tombstone, and the merge wrote an empty
 * fragment for each. Search was unaffected; every count was not.
 *
 * @group scolta
 */
class ForceBuildLedgerResetKernelTest extends KernelTestBase {

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

    // FilesystemDriver rejects the vfs:// public:// a kernel test mounts; see
    // ScopedBuildKernelTest.
    $this->indexRoot = sys_get_temp_dir() . '/scolta-force-ledger-' . uniqid();
    mkdir($this->indexRoot, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->indexRoot . '/output')
      ->set('pagefind.build_dir', $this->indexRoot . '/build')
      ->save();

    $this->createContentType(['type' => 'article']);
    for ($i = 1; $i <= 10; $i++) {
      Node::create([
        'type' => 'article',
        'title' => 'Article number ' . $i,
        'status' => 1,
        'body' => [
          'value' => '<p>' . str_repeat("Indexable prose about article {$i}. ", 30) . '</p>',
          'format' => 'plain_text',
        ],
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
   * Run the real scolta:build command against the test's index directory.
   *
   * @param array<string, mixed> $overrides
   *   Option values to override on top of the command's own defaults.
   */
  protected function runBuild(array $overrides = []): void {
    // Built from drush.services.yml's argument list: Drush's service provider
    // does not run in a kernel test.
    $commands = new ScoltaCommands(
      $this->container->get('config.factory'),
      $this->container->get('state'),
      $this->container->get('cache.default'),
      $this->container->get('scolta.ai_service'),
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('scolta.content_gatherer'),
      $this->container->get('file_system'),
      $this->container->get('cache_tags.invalidator'),
      $this->container->get('scolta.index_locator'),
      $this->container->get('scolta.index_build_runner'),
      $this->container->get('queue'),
      $this->container->get('entity_type.manager'),
      $this->container->get('scolta.reindexer'),
      $this->container->get('router.no_access_checks'),
    );
    $commands->setLogger(new DrushLoggerManager());
    $commands->setOutput(new NullOutput());

    $commands->build($overrides + [
      'entity-type' => 'node',
      'bundle' => '',
      'entity-ids' => '',
      'force' => FALSE,
      'memory-budget' => NULL,
      'chunk-size' => NULL,
      'resume' => FALSE,
      'restart' => FALSE,
      'reset-ledger' => FALSE,
    ]);
  }

  /**
   * The page_count the published pagefind-entry.json reports.
   */
  protected function publishedPageCount(): int {
    $path = $this->indexRoot . '/output/pagefind/pagefind-entry.json';
    $this->assertFileExists($path);
    $entry = json_decode((string) file_get_contents($path), TRUE);
    $counts = array_column($entry['languages'] ?? [], 'page_count');
    $this->assertNotSame([], $counts, 'pagefind-entry.json must list at least one language.');

    return (int) array_sum($counts);
  }

  /**
   * The page-table ledger the build under test wrote.
   */
  protected function ledger(): PageTableLedger {
    return new PageTableLedger($this->indexRoot . '/build', new FilesystemDriver());
  }

  /**
   * Delete $count article nodes, highest ID first.
   */
  protected function deleteNodes(int $count): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $nids = array_keys($storage->loadMultiple());
    rsort($nids);
    $storage->delete($storage->loadMultiple(array_slice($nids, 0, $count)));
  }

  /**
   * A forced build publishes exactly the pages it indexed.
   */
  public function testForcedBuildAfterDeletesCountsOnlySurvivingPages(): void {
    $this->runBuild();
    $this->assertSame(10, $this->publishedPageCount());

    $this->deleteNodes(4);
    $this->runBuild(['force' => TRUE]);

    $this->assertSame(
      6,
      $this->publishedPageCount(),
      'page_count must be the six surviving pages, not the ten ordinals the previous index used.',
    );
    $this->assertSame([], $this->ledger()->tombstones(), 'A forced build must leave no tombstoned ordinal behind.');
    $this->assertSame(6, $this->ledger()->liveCount());
  }

  /**
   * A scoped forced build stays a scoped build rather than being refused.
   *
   * A ledger reset is refused on a scoped build, so --force must not ask for
   * one there: --force --bundle keeps meaning "reload every entity in scope".
   */
  public function testForcedScopedBuildIsNotRefused(): void {
    $this->runBuild(['bundle' => 'article']);
    $this->runBuild(['bundle' => 'article', 'force' => TRUE]);

    $this->assertSame(10, $this->ledger()->liveCount());
    $this->assertSame(10, $this->publishedPageCount());
  }

}
