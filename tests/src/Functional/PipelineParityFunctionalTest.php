<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;

/**
 * Pipeline-parity regression test.
 *
 * The body-extraction + ContentItem construction block used to be duplicated
 * in the admin "Rebuild Index" button, the batch operations, and the cron
 * queue worker — and only ScoltaContentGatherer handled translations,
 * text-format rendering (->processed), field_mappings, and
 * hook_scolta_content_item_alter(). Those paths built a DIFFERENT index
 * than drush scolta:build, violating scolta.api.php's documented contract.
 *
 * This is the test that would have caught the whole class: a node with a
 * non-default text format, a translation, and a field mapping must come
 * out identically from every gathering entry point, and the queue-worker
 * build must index every gathered page.
 *
 * @group scolta
 */
class PipelineParityFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'node', 'language', 'filter', 'field'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The gathered baseline ContentItems.
   *
   * @var \Tag1\Scolta\Export\ContentItem[]
   */
  protected array $baselineItems;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    // Non-default text format whose filter strips <script> — the duplicated
    // pipelines indexed raw ->value, the gatherer renders ->processed.
    FilterFormat::create([
      'format' => 'restricted_test',
      'name' => 'Restricted test',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<p> <strong>'],
        ],
      ],
    ])->save();

    // A mapped field: field_mappings.filters routes it to a 'topic' filter.
    FieldStorageConfig::create([
      'field_name' => 'field_topic',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_topic',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Topic',
    ])->save();
    $this->config('scolta.settings')
      ->set('field_mappings.filters', ['field_topic' => 'topic'])
      ->save();

    // A second language so translations become separate indexed pages.
    ConfigurableLanguage::createFromLangcode('es')->save();

    // Node 1: non-default format with markup the format strips + mapping.
    $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Format node',
      'body' => [
        'value' => '<p>Visible body copy that is comfortably long enough to clear the fifty-character minimum content filter.</p><script>alert("never-index-me")</script>',
        'format' => 'restricted_test',
      ],
      'field_topic' => 'History',
      'status' => 1,
    ]);

    // Node 2: translated — must yield one page per translation.
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Translated node',
      'body' => [
        'value' => 'English body copy that is comfortably long enough to clear the minimum content length filter and be indexed.',
        'format' => 'plain_text',
      ],
      'status' => 1,
    ]);
    $node->addTranslation('es', [
      'title' => 'Nodo traducido',
      'body' => [
        'value' => 'Cuerpo del articulo en español que es lo bastante largo para superar el filtro de longitud minima y ser indexado.',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->baselineItems = iterator_to_array(
      $this->container->get('scolta.content_gatherer')->gather('node', '', 'Parity Site'),
      FALSE
    );
  }

  /**
   * The gatherer applies format rendering, translations, and mappings.
   *
   * This pins the baseline the other entry points are compared against.
   */
  public function testGathererBaselineCoversContract(): void {
    $this->assertCount(3, $this->baselineItems,
      'Two nodes, one with an es translation, must yield three pages');

    $byId = [];
    foreach ($this->baselineItems as $item) {
      $byId[$item->id] = $item;
    }

    // Text-format rendering: ->processed strips the script.
    $formatItem = current(array_filter($byId, fn($i) => $i->title === 'Format node'));
    $this->assertNotFalse($formatItem);
    $this->assertStringContainsString('Visible body copy', $formatItem->bodyHtml);
    $this->assertStringNotContainsString('never-index-me', $formatItem->bodyHtml,
      'The text format must be applied — raw ->value leaks unfiltered markup into the index');

    // Field mapping: field_topic → topic filter dimension.
    $this->assertSame('History', $formatItem->filters['topic'] ?? NULL,
      'field_mappings must map field_topic into the topic filter dimension');

    // Translation: a separate page with the -es suffix and language.
    $esItems = array_filter($this->baselineItems, fn($i) => $i->language === 'es');
    $this->assertCount(1, $esItems);
    $esItem = current($esItems);
    $this->assertStringContainsString('español', $esItem->bodyHtml);
    $this->assertStringEndsWith('-es', $esItem->id);
  }

  /**
   * gatherByIds() (the Batch API path) matches gather() exactly.
   */
  public function testBatchPathMatchesGatherer(): void {
    $ids = array_values(
      $this->container->get('entity_type.manager')->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->sort('nid', 'ASC')
        ->execute()
    );

    $byIds = iterator_to_array(
      $this->container->get('scolta.content_gatherer')->gatherByIds('node', $ids, 'Parity Site'),
      FALSE
    );

    $this->assertEquals($this->baselineItems, $byIds,
      'The batch ID-slice path must produce ContentItems identical to the streamed gather() path');
  }

  /**
   * The queue-worker build indexes every gathered page.
   */
  public function testQueueWorkerBuildsTheGatheredCorpus(): void {
    // No recorded content change → the debounce must not delay the build.
    \Drupal::state()->delete('scolta.rebuild_requested_at');

    $worker = $this->container->get('plugin.manager.queue_worker')
      ->createInstance('scolta_rebuild');
    $worker->processItem(['type' => 'parity-test']);

    $outputUri = $this->config('scolta.settings')->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $outputDir = $this->container->get('stream_wrapper_manager')->getViaUri($outputUri)->realpath();
    $this->assertNotFalse($outputDir);

    $locator = $this->container->get('scolta.index_locator');
    $location = $locator->locate($outputDir);
    $this->assertNotNull($location, 'The queue worker must build an index');

    $this->assertSame(
      count($this->baselineItems),
      $locator->countFragments($location),
      'The worker-built index must contain one fragment per gathered page — translations included. A mismatch means the worker bypassed the shared gatherer.'
    );

    $this->assertSame(1, \Drupal::state()->get('scolta.generation', 0),
      'A successful queue build must bump the AI-cache generation');
  }

  /**
   * A fresh content change debounces the queue rebuild.
   */
  public function testFreshChangeDebouncesQueueRebuild(): void {
    \Drupal::state()->set('scolta.rebuild_requested_at', time());

    $worker = $this->container->get('plugin.manager.queue_worker')
      ->createInstance('scolta_rebuild');

    $this->expectException(\Drupal\Core\Queue\SuspendQueueException::class);
    $worker->processItem(['type' => 'auto']);
  }

}
