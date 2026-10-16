<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * Every gathered item carries a `type` meta value, '<entity type>:<bundle>'.
 *
 * scoring.metadata_boosts keys on it (see ScoltaContentGatherer::typeKey()),
 * so a missing or differently shaped value would silently disable every
 * configured type boost rather than fail.
 *
 * @group scolta
 */
class TypeMetaKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta', 'scolta_ui', 'node', 'field', 'filter', 'text'];

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
   * The type key is the entity type ID and bundle, colon-separated.
   */
  public function testGatheredItemCarriesTypeMeta(): void {
    $this->createNode([
      'type' => 'article',
      'body' => ['value' => 'Body text long enough to be indexed by the gatherer.', 'format' => 'plain_text'],
      'status' => 1,
    ]);

    $items = iterator_to_array(\Drupal::service('scolta.content_gatherer')->gather('node', '', 'Test site'), FALSE);
    $this->assertCount(1, $items);
    $this->assertSame('node:article', $items[0]->metadata['type']);
  }

}
