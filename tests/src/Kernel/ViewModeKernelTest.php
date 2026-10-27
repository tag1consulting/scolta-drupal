<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Each entity is indexed as it renders in the configured view mode.
 *
 * ScoltaContentGatherer renders each translation the way Drupal's search module
 * and Search API do: in the view_mode setting, falling back to the bundle's
 * default display, as the anonymous user, in the site's default theme.
 *
 * @group scolta
 */
class ViewModeKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta', 'node', 'field', 'filter', 'text', 'language', 'dblog',
    'scolta_view_mode_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['system', 'scolta', 'field', 'node', 'filter']);

    // Articles show body, a teaser line and a staff note by default; their
    // search_index display leaves the teaser line out.
    $this->createContentType(['type' => 'article']);
    foreach (['field_teaser_line', 'field_staff_note'] as $field) {
      FieldStorageConfig::create(['field_name' => $field, 'entity_type' => 'node', 'type' => 'string'])->save();
      FieldConfig::create(['field_name' => $field, 'entity_type' => 'node', 'bundle' => 'article', 'label' => $field])->save();
    }
    $repository = $this->container->get('entity_display.repository');
    $repository->getViewDisplay('node', 'article')
      ->setComponent('field_teaser_line', ['type' => 'string'])
      ->setComponent('field_staff_note', ['type' => 'string'])
      ->save();
    // Drupal 10 ships this view mode with the node module; 11 moved it to
    // search_node.
    if (!EntityViewMode::load('node.search_index')) {
      EntityViewMode::create(['id' => 'node.search_index', 'targetEntityType' => 'node', 'label' => 'Search index'])->save();
    }
    $repository->getViewDisplay('node', 'article', 'search_index')
      ->setStatus(TRUE)
      ->setComponent('body', ['type' => 'text_default'])
      ->setComponent('field_staff_note', ['type' => 'string'])
      ->removeComponent('field_teaser_line')
      ->save();

    // Pages have a default display and no search_index display.
    $this->createContentType(['type' => 'page']);

    $this->config('scolta.settings')->set('view_mode', 'search_index')->save();
  }

  /**
   * Every gathered item for nodes, keyed by item ID.
   *
   * @return \Tag1\Scolta\Export\ContentItem[]
   */
  private function gatherNodes(): array {
    $items = [];
    foreach ($this->container->get('scolta.content_gatherer')->gather('node', '', 'Test site') as $item) {
      $items[$item->id] = $item;
    }
    return $items;
  }

  /**
   * Create a published article.
   */
  private function createArticle(array $values = []): Node {
    $node = Node::create($values + [
      'type' => 'article',
      'title' => 'Field guide',
      'status' => 1,
      'body' => ['value' => '<p>The aardvark digs at night.</p>', 'format' => 'plain_text'],
      'field_teaser_line' => 'Okapis are shy.',
      'field_staff_note' => 'Capybaras need a bigger pond.',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Every field the display shows is indexed, not only one body field.
   */
  public function testEveryFieldTheDisplayShowsIsIndexed(): void {
    $this->config('scolta.settings')->set('view_mode', 'default')->save();
    $node = $this->createArticle();

    $body = $this->gatherNodes()['node:' . $node->id()]->bodyHtml;
    $this->assertStringContainsString('Field guide', $body);
    $this->assertStringContainsString('The aardvark digs at night.', $body);
    $this->assertStringContainsString('Okapis are shy.', $body);
  }

  /**
   * A field the search_index display hides is not indexed unless default is.
   */
  public function testAFieldHiddenInTheConfiguredDisplayIsNotIndexed(): void {
    $node = $this->createArticle();
    $id = 'node:' . $node->id();

    $body = $this->gatherNodes()[$id]->bodyHtml;
    $this->assertStringContainsString('The aardvark digs at night.', $body);
    $this->assertStringNotContainsString('Okapis are shy.', $body);

    $this->config('scolta.settings')->set('view_mode', 'default')->save();
    $this->assertStringContainsString('Okapis are shy.', $this->gatherNodes()[$id]->bodyHtml);
  }

  /**
   * A bundle without the configured display is indexed from its default one.
   */
  public function testABundleWithoutTheDisplayFallsBackToDefault(): void {
    $page = Node::create([
      'type' => 'page',
      'title' => 'About',
      'status' => 1,
      'body' => ['value' => 'Pangolins roll into a ball.', 'format' => 'plain_text'],
    ]);
    $page->save();

    $this->assertNull($this->container->get('entity_type.manager')->getStorage('entity_view_display')->load('node.page.search_index'));
    $this->assertStringContainsString('Pangolins roll into a ball.', $this->gatherNodes()['node:' . $page->id()]->bodyHtml);
  }

  /**
   * A field anonymous visitors may not view stays out, whoever builds.
   */
  public function testAFieldAnonymousMayNotViewIsNotIndexed(): void {
    $admin = $this->setUpCurrentUser(['uid' => 1]);
    $node = $this->createArticle();

    // The builder can see the note, so leaving it out is the gatherer's doing.
    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($node, 'search_index');
    $this->assertStringContainsString('Capybaras need a bigger pond.', (string) $this->container->get('renderer')->renderInIsolation($build));

    $body = $this->gatherNodes()['node:' . $node->id()]->bodyHtml;
    $this->assertStringContainsString('The aardvark digs at night.', $body);
    $this->assertStringNotContainsString('Capybaras need a bigger pond.', $body);
    $this->assertSame($admin->id(), $this->container->get('current_user')->id(), 'The builder is the current user again after the gather.');
  }

  /**
   * Each translation is rendered in its own language, with its own text.
   */
  public function testEachTranslationIsIndexedWithItsOwnText(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();
    $node = $this->createArticle();
    $node->addTranslation('es', [
      'title' => 'Guía de campo',
      'body' => ['value' => 'El oso hormiguero cava de noche.', 'format' => 'plain_text'],
      'field_staff_note' => 'Los carpinchos necesitan un estanque.',
    ])->save();

    $items = $this->gatherNodes();
    $id = 'node:' . $node->id();
    $this->assertSame([$id, $id . '-es'], array_keys($items));
    $this->assertStringContainsString('The aardvark digs at night.', $items[$id]->bodyHtml);
    $this->assertStringNotContainsString('oso hormiguero', $items[$id]->bodyHtml);
    $this->assertStringContainsString('El oso hormiguero cava de noche.', $items[$id . '-es']->bodyHtml);
    $this->assertStringNotContainsString('aardvark', $items[$id . '-es']->bodyHtml);
  }

  /**
   * A translation that fails to render is logged and skipped; the rest index.
   */
  public function testATranslationThatFailsToRenderIsLoggedAndSkipped(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();
    $node = $this->createArticle();
    $node->addTranslation('es', ['title' => 'Render failure'])->save();
    $other = $this->createArticle(['title' => 'Second guide']);

    $this->assertSame(['node:' . $node->id(), 'node:' . $other->id()], array_keys($this->gatherNodes()));

    $logged = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['message', 'variables', 'severity'])
      ->condition('type', 'scolta')
      ->execute()
      ->fetchAll();
    $this->assertCount(1, $logged);
    $this->assertSame(4, (int) $logged[0]->severity, 'Logged as a warning.');
    $variables = unserialize($logged[0]->variables, ['allowed_classes' => FALSE]);
    $this->assertSame('node', $variables['@type']);
    $this->assertSame((string) $node->id(), (string) $variables['@id']);
    $this->assertSame('es', $variables['@langcode']);
    $this->assertSame('A broken formatter on es', $variables['@message']);
  }

  /**
   * Rendering happens in the default theme, and the active theme comes back.
   */
  public function testRendersInTheDefaultThemeAndRestoresTheActiveOne(): void {
    $this->container->get('theme_installer')->install(['stark', 'olivero']);
    $this->config('system.theme')->set('default', 'olivero')->save();
    $themeManager = $this->container->get('theme.manager');
    $themeManager->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName('stark'));
    $node = $this->createArticle();

    $this->assertArrayHasKey('node:' . $node->id(), $this->gatherNodes());
    $this->assertSame('olivero', $this->container->get('state')->get('scolta_view_mode_test.theme'));
    $this->assertSame('stark', $themeManager->getActiveTheme()->getName());
  }

  /**
   * A gather leaves no render cache entries behind.
   */
  public function testAGatherWritesNothingToTheRenderCache(): void {
    $node = $this->createArticle();
    $bin = $this->container->get('cache.render');
    $this->assertInstanceOf(MemoryBackend::class, $bin);
    $entries = static fn (): int => count((fn () => $this->cache)->call($bin));

    // An ordinary render of the same node does write one, so the count below
    // can see an entry when there is one.
    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($node, 'search_index');
    $this->container->get('renderer')->renderInIsolation($build);
    $this->assertGreaterThan(0, $entries());
    $bin->deleteAll();

    $this->assertArrayHasKey('node:' . $node->id(), $this->gatherNodes());
    $this->assertSame(0, $entries());
  }

  /**
   * Drops the retired field list setting, sets view_mode, forces a rebuild.
   */
  public function testUpdateHookMovesSitesToTheViewMode(): void {
    // Written to storage directly, as an unreleased build of main left it: the
    // key is no longer in the schema, so a config save would refuse it.
    $storage = $this->container->get('config.storage');
    $settings = $storage->read('scolta.settings');
    unset($settings['view_mode']);
    $storage->write('scolta.settings', $settings + ['body_fields' => ['body', 'field_recipe_instruction']]);
    $this->container->get('config.factory')->reset('scolta.settings');
    $queue = $this->container->get('queue')->get('scolta_rebuild');
    $queue->deleteQueue();

    $this->container->get('module_handler')->loadInclude('scolta', 'install');
    $message = (string) scolta_update_10011();

    $settings = $this->config('scolta.settings');
    $this->assertNull($settings->get('body_fields'));
    $this->assertSame('search_index', $settings->get('view_mode'));
    $this->assertStringContainsString('search_index view mode', $message);
    $this->assertStringContainsString('/admin/config/search/scolta', $message);
    $item = $queue->claimItem();
    $this->assertSame(['type' => 'scolta_update_10011', 'force' => TRUE], $item->data);
  }

}
