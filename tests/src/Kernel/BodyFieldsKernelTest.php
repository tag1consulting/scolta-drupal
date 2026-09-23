<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * The configurable body_fields list picks the fields that get indexed.
 *
 * ScoltaContentGatherer::bodyFields() (src/Service/ScoltaContentGatherer.php)
 * reads scolta.settings.body_fields and falls back to the historical
 * ['body', 'field_body', 'field_content'] list when it is unset or emptied.
 * Every listed field holding a value is indexed, in list order, and a field
 * left off the list is not.
 * The comment above the call site records why the list is configurable at
 * all: "Umami's recipe nodes carry theirs in field_recipe_instruction, and
 * with a hardcoded list every recipe fell out of the index at the empty-body
 * check below without a word." A bundle whose prose lives in a field this
 * setting doesn't name is indexed as though it had no content — silently,
 * since an empty body just means the translation is skipped — so this test
 * exists to keep that regression from recurring unannounced.
 *
 * @group scolta
 */
class BodyFieldsKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta', 'node', 'field', 'filter', 'text'];

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

    FieldStorageConfig::create([
      'field_name' => 'field_recipe_instruction',
      'entity_type' => 'node',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_recipe_instruction',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Recipe instructions',
    ])->save();
  }

  /**
   * The gathered body text for the one node this site has.
   */
  private function gatheredBody(): ?string {
    $gatherer = \Drupal::service('scolta.content_gatherer');
    foreach ($gatherer->gather('node', '', 'Test site') as $item) {
      return $item->bodyHtml;
    }
    return NULL;
  }

  /**
   * With no configured list, the historical default fields are used.
   */
  public function testDefaultFieldsAreUsedWhenUnconfigured(): void {
    $this->config('scolta.settings')->clear('body_fields')->save();

    $this->createNode([
      'type' => 'article',
      'body' => ['value' => 'Body text long enough to matter for the test.', 'format' => 'plain_text'],
      'status' => 1,
    ]);

    $this->assertStringContainsString('Body text long enough to matter', (string) $this->gatheredBody());
  }

  /**
   * A configured non-standard field is indexed when the default is empty.
   */
  public function testConfiguredFieldIsUsedWhenBodyIsEmpty(): void {
    $this->config('scolta.settings')
      ->set('body_fields', ['body', 'field_recipe_instruction'])
      ->save();

    $this->createNode([
      'type' => 'article',
      // createNode() auto-fills 'body' with random text when it is omitted
      // and the bundle has one; must be set explicitly empty to test the
      // "default field is empty" case.
      'body' => ['value' => '', 'format' => 'plain_text'],
      'field_recipe_instruction' => [
        'value' => 'Preheat the oven to 400 degrees before starting.',
        'format' => 'plain_text',
      ],
      'status' => 1,
    ]);

    $this->assertStringContainsString('Preheat the oven to 400 degrees', (string) $this->gatheredBody());
  }

  /**
   * Every configured field holding a value is indexed, in list order.
   *
   * Umami's recipes keep their prose in three fields; indexing only the first
   * one with a value left the others unsearchable whatever the order.
   */
  public function testEveryPopulatedConfiguredFieldIsIndexedInListOrder(): void {
    $this->config('scolta.settings')
      ->set('body_fields', ['field_recipe_instruction', 'body'])
      ->save();

    $this->createNode([
      'type' => 'article',
      'body' => ['value' => 'A quick and easy version of a classic.', 'format' => 'plain_text'],
      'field_recipe_instruction' => ['value' => 'Preheat the oven to 400 degrees.', 'format' => 'plain_text'],
      'status' => 1,
    ]);

    $body = (string) $this->gatheredBody();
    $instruction = strpos($body, 'Preheat the oven to 400 degrees');
    $summary = strpos($body, 'A quick and easy version of a classic');
    $this->assertNotFalse($instruction, 'The first configured field is indexed.');
    $this->assertNotFalse($summary, 'A later configured field with a value is indexed too.');
    $this->assertLessThan($summary, $instruction, 'Fields are joined in body_fields order, not field-definition order.');
  }

  /**
   * Every item of a multi-value body field is indexed, not only the first.
   */
  public function testEveryItemOfAMultiValueFieldIsIndexed(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_ingredients',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_ingredients',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Ingredients',
    ])->save();
    $this->config('scolta.settings')
      ->set('body_fields', ['body', 'field_ingredients'])
      ->save();

    $this->createNode([
      'type' => 'article',
      'body' => ['value' => '', 'format' => 'plain_text'],
      'field_ingredients' => ['2 cups flour', '1 tsp lemongrass'],
      'status' => 1,
    ]);

    $body = (string) $this->gatheredBody();
    $this->assertStringContainsString('2 cups flour', $body);
    $this->assertStringContainsString('1 tsp lemongrass', $body);
  }

  /**
   * A populated field missing from body_fields is not indexed.
   *
   * Removing a field from the list is how a site keeps it out of the index,
   * for example a legacy field still populated beside its replacement.
   */
  public function testPopulatedFieldMissingFromTheListIsNotIndexed(): void {
    $this->config('scolta.settings')
      ->set('body_fields', ['body'])
      ->save();

    $this->createNode([
      'type' => 'article',
      'body' => ['value' => 'The listed field is indexed.', 'format' => 'plain_text'],
      'field_recipe_instruction' => ['value' => 'This unlisted text must not be indexed.', 'format' => 'plain_text'],
      'status' => 1,
    ]);

    $body = (string) $this->gatheredBody();
    $this->assertStringContainsString('The listed field is indexed', $body);
    $this->assertStringNotContainsString('This unlisted text must not be indexed', $body);
  }

  /**
   * An entity with no value in any configured field is silently skipped.
   *
   * This is the failure mode the whole setting exists to prevent: a bundle
   * whose prose lives outside the configured list produces zero indexed
   * pages for that entity, with no error and no log entry.
   */
  public function testEntityIsSkippedWithNoValueInAnyConfiguredField(): void {
    $this->config('scolta.settings')
      ->set('body_fields', ['field_recipe_instruction'])
      ->save();

    $this->createNode([
      'type' => 'article',
      'body' => ['value' => 'This body is never checked because it is not configured.', 'format' => 'plain_text'],
      'status' => 1,
    ]);

    $this->assertNull($this->gatheredBody(), 'A node with no value in any configured body field must yield nothing.');
  }

}
