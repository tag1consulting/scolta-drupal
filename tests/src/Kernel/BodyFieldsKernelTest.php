<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * The configurable body_fields list picks the field that gets indexed.
 *
 * ScoltaContentGatherer::bodyFields() (src/Service/ScoltaContentGatherer.php)
 * reads scolta.settings.body_fields and falls back to the historical
 * ['body', 'field_body', 'field_content'] list when it is unset or emptied.
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
   * The first configured field with a value wins over later ones.
   */
  public function testFirstNonEmptyConfiguredFieldWins(): void {
    $this->config('scolta.settings')
      ->set('body_fields', ['body', 'field_recipe_instruction'])
      ->save();

    $this->createNode([
      'type' => 'article',
      'body' => ['value' => 'Body wins over the recipe field.', 'format' => 'plain_text'],
      'field_recipe_instruction' => ['value' => 'This text must not be indexed.', 'format' => 'plain_text'],
      'status' => 1,
    ]);

    $body = (string) $this->gatheredBody();
    $this->assertStringContainsString('Body wins over the recipe field', $body);
    $this->assertStringNotContainsString('This text must not be indexed', $body);
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
