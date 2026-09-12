<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * A build releases its own memory and nothing shared with web requests.
 *
 * ScoltaContentGatherer::releaseBatch() used to call the storage's
 * resetCache(), which deletes every indexed entity from the persistent
 * cache.entity bin and invalidates its revision tags, so web requests during
 * and after a full build reloaded every node from the database. Indexing is a
 * read-only walk: the only thing it must release is the process-local
 * entity.memory_cache, which would otherwise hold the whole corpus.
 *
 * @group scolta
 */
class GathererCacheReleaseKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta', 'node', 'field', 'filter', 'text'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installConfig(['scolta', 'field', 'node', 'filter']);
    $this->installSchema('node', ['node_access']);
    $this->createContentType(['type' => 'article']);
  }

  /**
   * The persistent entity cache survives a walk; the memory cache is emptied.
   */
  public function testWalkKeepsPersistentEntityCacheAndEmptiesMemoryCache(): void {
    $node = $this->createNode(['type' => 'article', 'body' => [['value' => '<p>Indexed</p>', 'format' => 'plain_text']]]);
    $cid = 'values:node:' . $node->id();

    // Warm the persistent entity cache the way a web request would.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    \Drupal::entityTypeManager()->getStorage('node')->load($node->id());
    $this->assertNotFalse(\Drupal::cache('entity')->get($cid), 'Precondition: the node is in cache.entity.');

    $items = iterator_to_array(\Drupal::service('scolta.content_gatherer')->gather('node', '', 'Test site'), FALSE);
    $this->assertCount(1, $items);

    $this->assertNotFalse(\Drupal::cache('entity')->get($cid), 'A read-only walk must not evict the node from the persistent entity cache.');
    $this->assertFalse(\Drupal::service('entity.memory_cache')->get($cid), 'The process-local entity cache is released after the walk.');
  }

}
