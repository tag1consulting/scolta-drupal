<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\scolta\Commands\ScoltaCommands;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drush\Log\DrushLoggerManager;
use Symfony\Component\Console\Output\NullOutput;

/**
 * `scolta:inspect` reads back the fragment the index holds for an entity.
 *
 * Fragment files are named by a content hash, so the command joins entity to
 * fragment through the page-table ledger and the pf_meta page table. Both come
 * from a real build here, because hand-written fixtures would only prove the
 * command agrees with itself about their layout.
 *
 * The output dir is a real temp path rather than public://, because
 * KernelTestBase mounts public:// on vfsStream — see
 * IncrementalQueueUpdateKernelTest.
 *
 * @group scolta
 */
class InspectCommandKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta', 'scolta_ui', 'node', 'filter', 'field', 'text', 'dblog',
  ];

  /**
   * Real filesystem directory holding the index and the build state.
   */
  private string $dir = '';

  /**
   * The two seeded nodes.
   *
   * @var \Drupal\node\Entity\Node[]
   */
  private array $nodes = [];

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

    $this->dir = sys_get_temp_dir() . '/scolta-inspect-test-' . uniqid();
    mkdir($this->dir, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->dir . '/output')
      ->set('pagefind.build_dir', $this->dir . '/build')
      ->save();

    $this->createContentType(['type' => 'article']);
    foreach (['zebras', 'axolotls'] as $animal) {
      $node = Node::create([
        'type' => 'article',
        'title' => 'About ' . $animal,
        'body' => [
          'value' => 'Seeded body text about ' . $animal . ', written at length so the exporter minimum content length is comfortably cleared.',
          'format' => 'plain_text',
        ],
        'status' => 1,
      ]);
      $node->save();
      $this->nodes[] = $node;
    }

    \Drupal::state()->delete('scolta.rebuild_requested_at');
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance('scolta_rebuild')
      ->processItem(['type' => 'install']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->dir !== '' && is_dir($this->dir)) {
      $this->container->get('file_system')->deleteRecursive($this->dir);
    }
    parent::tearDown();
  }

  /**
   * The command object, wired the way drush.services.yml wires it.
   */
  private function commands(): ScoltaCommands {
    $commands = new ScoltaCommands(
      $this->container->get('config.factory'),
      $this->container->get('state'),
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
    return $commands;
  }

  /**
   * A URL resolves to its entity and returns that entity's fragment only.
   */
  public function testByUrl(): void {
    $node = $this->nodes[1];
    $result = $this->commands()->inspect('/node/' . $node->id())->getArrayCopy();

    $this->assertSame(['node:' . $node->id()], array_keys($result));
    $fragment = $result['node:' . $node->id()];
    $this->assertSame($node->toUrl()->toString(), $fragment['url']);
    $this->assertStringContainsString('axolotls', $fragment['content']);
    $this->assertStringNotContainsString('zebras', $fragment['content']);
  }

  /**
   * Entity type and ID name the same entity the URL does.
   */
  public function testByTypeAndId(): void {
    $node = $this->nodes[0];
    $result = $this->commands()
      ->inspect('', ['entity-type' => 'node', 'entity-id' => (string) $node->id(), 'format' => 'yaml'])
      ->getArrayCopy();

    $this->assertSame(['node:' . $node->id()], array_keys($result));
    $this->assertStringContainsString('zebras', $result['node:' . $node->id()]['content']);
  }

  /**
   * An unknown type, a missing ID, a non-entity path, and no selector are errors.
   */
  public function testBadSelectorsAreErrors(): void {
    $commands = $this->commands();
    foreach ([
      [['entity-type' => 'recipe', 'entity-id' => '1'], 'No such entity type: recipe'],
      [['entity-type' => 'node', 'entity-id' => ''], 'Pass a URL, or both'],
      [['entity-type' => 'node', 'entity-id' => '999'], 'No node with ID 999'],
    ] as [$options, $message]) {
      try {
        $commands->inspect('', $options + ['format' => 'yaml']);
        $this->fail('Expected an exception: ' . $message);
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString($message, $e->getMessage());
      }
    }
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('does not route to an entity');
    $commands->inspect('/admin/content');
  }

}
