<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\scolta\Commands\ScoltaCommands;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drush\Log\DrushLoggerManager;
use Symfony\Component\Console\Output\NullOutput;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * One index can hold more than one entity type.
 *
 * Nodes and taxonomy terms share the ID space 1..n, so without namespacing
 * node 1 and term 1 would claim the same page. Every page ID is prefixed with
 * its entity type ID.
 *
 * @group scolta
 */
class MultiEntityTypeBuildKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta', 'search_api', 'node', 'taxonomy', 'filter', 'field', 'text', 'dblog',
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
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['scolta', 'field', 'node', 'filter']);

    // A real directory: scolta-php's FilesystemDriver rejects vfs:// URIs.
    $this->indexRoot = sys_get_temp_dir() . '/scolta-multi-type-' . uniqid();
    mkdir($this->indexRoot, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->indexRoot . '/output')
      ->set('pagefind.build_dir', $this->indexRoot . '/build')
      ->set('entity_types', ['node' => [], 'taxonomy_term' => ['tags']])
      ->set('body_fields', ['body', 'description'])
      ->save();

    $this->createContentType(['type' => 'article']);
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    // A vocabulary outside the configured bundles: never indexed, never queued.
    Vocabulary::create(['vid' => 'other', 'name' => 'Other'])->save();
    Term::create([
      'vid' => 'other',
      'name' => 'Unindexed term',
      'status' => 1,
      'description' => ['value' => str_repeat('Prose about okapis nobody should find. ', 30), 'format' => 'plain_text'],
    ])->save();

    for ($i = 1; $i <= 3; $i++) {
      Node::create([
        'type' => 'article',
        'title' => 'Article ' . $i,
        'status' => 1,
        'body' => ['value' => str_repeat("Node prose about aardvarks {$i}. ", 30), 'format' => 'plain_text'],
      ])->save();
      Term::create([
        'vid' => 'tags',
        'name' => 'Term ' . $i,
        'status' => 1,
        'description' => ['value' => str_repeat("Term prose about capybaras {$i}. ", 30), 'format' => 'plain_text'],
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
   * Item IDs are prefixed with the entity type.
   */
  public function testItemIdsAreNamespacedByEntityType(): void {
    $gatherer = $this->container->get('scolta.content_gatherer');

    $this->assertSame(['node:1'], $gatherer->itemIdsFor(Node::load(1)));
    $this->assertSame(['taxonomy_term:2'], $gatherer->itemIdsFor(Term::load(2)));
  }

  /**
   * A build indexes every configured type and only the configured bundles.
   */
  public function testABuildIndexesEveryConfiguredEntityTypeAndBundle(): void {
    $this->runBuild();

    $ids = iterator_to_array($this->ledger()->seenIdsThisBuild(), FALSE);
    sort($ids);
    $this->assertSame(['node:1', 'node:2', 'node:3', 'taxonomy_term:2', 'taxonomy_term:3', 'taxonomy_term:4'], $ids,
      'Term 1 is in the unconfigured vocabulary and must be left out.');
    $this->assertSame(6, $this->ledger()->liveCount());
  }

  /**
   * Saving an entity outside the configured bundles enqueues nothing.
   */
  public function testAnEntityOutsideTheConfiguredBundlesIsNotQueued(): void {
    \Drupal::queue('scolta_rebuild')->deleteQueue();

    $term = Term::load(1);
    $term->setName('Still unindexed')->save();
    $this->assertSame(0, \Drupal::queue('scolta_rebuild')->numberOfItems());

    $term = Term::load(2);
    $term->setName('Indexed tag')->save();
    $this->assertSame(1, \Drupal::queue('scolta_rebuild')->numberOfItems());
  }

  /**
   * A term edit is applied incrementally through the queue, like a node edit.
   */
  public function testATermEditTakesTheIncrementalPath(): void {
    $this->runBuild();
    \Drupal::queue('scolta_rebuild')->deleteQueue();

    $term = Term::load(3);
    $term->set('description', [
      'value' => str_repeat('Rewritten term prose about pangolins. ', 30),
      'format' => 'plain_text',
    ]);
    $term->setChangedTime($term->getChangedTime() + 60);
    $term->save();

    $queue = \Drupal::queue('scolta_rebuild');
    $item = $queue->claimItem();
    $this->assertNotFalse($item, 'Saving an entity of a configured type must enqueue a rebuild request.');
    $this->assertSame('taxonomy_term', $item->data['entity_type']);
    $this->assertSame(['taxonomy_term:3'], $item->data['item_ids']);

    \Drupal::state()->delete('scolta.rebuild_requested_at');
    $this->container->get('plugin.manager.queue_worker')->createInstance('scolta_rebuild')->processItem($item->data);

    $logged = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['message'])
      ->condition('type', 'scolta')
      ->condition('message', '%updated incrementally%', 'LIKE')
      ->countQuery()->execute()->fetchField();
    $this->assertGreaterThan(0, (int) $logged, 'A term edit must be applied incrementally, not by a full rebuild.');
    $this->assertSame(6, $this->ledger()->liveCount());
  }

  /**
   * The real command, wired for a process that has no Drush runtime.
   */
  protected function runBuild(): void {
    $commands = new ScoltaCommands(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('http_client'),
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
    );
    $commands->setLogger(new DrushLoggerManager());
    $commands->setOutput(new NullOutput());
    $commands->build([
      'entity-type' => '',
      'bundle' => '',
      'entity-ids' => '',
      'output-dir' => $this->indexRoot . '/export',
      'skip-pagefind' => FALSE,
      'indexer' => 'php',
      'force' => FALSE,
      'memory-budget' => NULL,
      'chunk-size' => NULL,
      'resume' => FALSE,
      'restart' => FALSE,
      'reset-ledger' => FALSE,
    ]);
  }

  /**
   * The page-table ledger the build under test wrote.
   */
  protected function ledger(): PageTableLedger {
    return new PageTableLedger($this->indexRoot . '/build', new FilesystemDriver());
  }

}
