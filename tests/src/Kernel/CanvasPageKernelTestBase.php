<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Page;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * Shared setup for the Canvas page tests: Canvas, Spanish, and test components.
 *
 * Canvas is not a dependency of this module and is not installed in CI, so
 * every test built on this skips unless the drupal/canvas package is present
 * in the code base, as it is on a site that indexes Canvas pages.
 */
abstract class CanvasPageKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta',
    // Canvas and its dependencies, as Canvas's own kernel tests list them.
    'canvas', 'block', 'editor', 'ckeditor5', 'filter', 'text', 'datetime',
    'file', 'image', 'link', 'media_library', 'options', 'path', 'media',
    'path_alias', 'views', 'field',
    'language', 'content_translation',
    'scolta_canvas_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The test bootstrap registers the namespace of every module in the code
    // base, so the class exists exactly when the canvas module does.
    if (!class_exists(Page::class)) {
      $this->markTestSkipped('Canvas is not installed, so there are no Canvas pages to index.');
    }

    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('canvas_page');
    $this->installConfig(['system', 'scolta', 'canvas', 'language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->container->get('content_translation.manager')->setEnabled(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, TRUE);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    $this->config('scolta.settings')
      ->set('entity_types', [Page::ENTITY_TYPE_ID => []])
      ->save();
  }

  /**
   * A results page, deliberately stored out of page order.
   *
   * The card sits in the section's second slot but is stored before the
   * heading in the first, so field order and page order disagree.
   *
   * @param array $text
   *   The text inputs: heading, card title, card body, link label, closing.
   *
   * @return array
   *   The component tree field value.
   */
  protected function resultsTree(array $text): array {
    return [
      [
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'component_id' => 'sdc.scolta_canvas_test.section',
        'inputs' => ['background' => 'accent-panel'],
      ],
      [
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000002',
        'component_id' => 'sdc.scolta_canvas_test.card',
        'parent_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'slot' => 'content',
        'inputs' => [
          'title' => $text['card'],
          'body' => $text['body'],
          'image_url' => '/sites/default/files/dashi-results-2026.jpg',
          'link_label' => $text['link'],
          'link_url' => '/investors/results',
          'style' => 'card--highlight',
        ],
      ],
      [
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000003',
        'component_id' => 'sdc.scolta_canvas_test.heading',
        'parent_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'slot' => 'header',
        'inputs' => [
          'text' => $text['heading'],
          'level' => 2,
          'anchor' => '7d0c4f5e-3b1a-4c2d-9e8f-6a5b4c3d2e1f',
        ],
      ],
      [
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000004',
        'component_id' => 'sdc.scolta_canvas_test.heading',
        'inputs' => ['text' => $text['closing'], 'level' => 3],
      ],
    ];
  }

  /**
   * English text for resultsTree().
   */
  protected function englishText(): array {
    return [
      'heading' => 'Results for the year',
      'card' => 'Revenue',
      'body' => '<p>Revenue rose to <strong>1.2 billion</strong>.</p>',
      'link' => 'Full results',
      'closing' => 'What the chief executive said',
    ];
  }

  /**
   * Spanish text for resultsTree().
   */
  protected function spanishText(): array {
    return [
      'heading' => 'Resultados del año',
      'card' => 'Ingresos',
      'body' => '<p>Los ingresos subieron a <strong>1200 millones</strong>.</p>',
      'link' => 'Resultados completos',
      'closing' => 'Lo que dijo el consejero delegado',
    ];
  }

  /**
   * Create and save a Canvas page, optionally with a Spanish translation.
   */
  protected function createPage(string $title, array $text, array $values = [], ?array $spanish = NULL): Page {
    $page = Page::create($values + [
      'title' => $title,
      'status' => TRUE,
      'components' => $this->resultsTree($text),
    ]);
    $page->save();

    if ($spanish !== NULL) {
      $page->addTranslation('es', [
        'title' => $title . ' (es)',
        'status' => TRUE,
        'components' => $this->resultsTree($spanish),
      ])->save();
    }

    return $page;
  }

  /**
   * Every item the gatherer yields for Canvas pages, keyed by item ID.
   *
   * @return \Tag1\Scolta\Export\ContentItem[]
   */
  protected function gatherPages(): array {
    $items = [];
    foreach ($this->container->get('scolta.content_gatherer')->gather(Page::ENTITY_TYPE_ID, '', 'Test site') as $item) {
      $items[$item->id] = $item;
    }
    return $items;
  }

}
