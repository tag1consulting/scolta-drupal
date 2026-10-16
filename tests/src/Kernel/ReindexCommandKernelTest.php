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
 * The CLI half of `drush scolta:reindex`.
 *
 * The queueing itself is ScoltaReindexer's, covered by
 * ScoltaReindexerKernelTest. What is left here is what only the command does:
 * validating the entity type and bundle, and resolving an omitted --ids to
 * every published entity of a bundle.
 *
 * @group scolta
 */
class ReindexCommandKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta', 'scolta_ui', 'node', 'filter', 'field', 'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['scolta', 'field', 'node', 'filter']);

    $this->createContentType(['type' => 'article']);
  }

  /**
   * An omitted --ids reindexes every published entity of the bundle.
   */
  public function testBundleTargetsEveryPublishedEntity(): void {
    for ($i = 0; $i < 3; $i++) {
      Node::create(['type' => 'article', 'title' => 'Article ' . $i, 'status' => 1])->save();
    }
    Node::create(['type' => 'article', 'title' => 'Draft', 'status' => 0])->save();
    // Each save queued an incremental update of its own; only what the
    // command queues is under test here.
    \Drupal::queue('scolta_rebuild')->deleteQueue();

    $this->commands()->reindex('node', 'article');

    $this->assertSame(3, \Drupal::queue('scolta_rebuild')->numberOfItems(),
      'Only the published articles are queued');
  }

  /**
   * An entity type or bundle Scolta does not index is refused.
   */
  public function testUnknownEntityTypeAndBundleAreRefused(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('is not indexed by Scolta');
    $this->commands()->reindex('taxonomy_term');
  }

  /**
   * The Drush command object, wired from the container.
   */
  protected function commands(): ScoltaCommands {
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

}
