<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;

/**
 * Canvas pages are gathered from their component trees.
 *
 * A Canvas page has no body field; its text is the inputs of the components
 * placed on it. Before this, the gatherer's body field lookup found nothing
 * and dropped every Canvas page as empty, so a page built in Canvas could not
 * be found in search at all.
 *
 * @group scolta
 */
class CanvasPageGatherKernelTest extends CanvasPageKernelTestBase {

  use UserCreationTrait;
  use WorkspaceTestTrait;

  /**
   * The entity type manager, set by WorkspaceTestTrait.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The workspace manager, set by WorkspaceTestTrait.
   *
   * @var \Drupal\workspaces\WorkspaceManagerInterface
   */
  protected $workspaceManager;

  /**
   * The test workspaces, keyed by ID, set by WorkspaceTestTrait.
   *
   * @var \Drupal\workspaces\WorkspaceInterface[]
   */
  protected $workspaces = [];

  /**
   * Reader-visible text in page order, and nothing a visitor never reads.
   */
  public function testTextInPageOrderAndOnlyReaderVisibleText(): void {
    $page = $this->createPage('Results', $this->englishText(), ['path' => ['alias' => '/results-2026']]);

    $items = $this->gatherPages();
    $this->assertSame(['canvas_page:' . $page->id()], array_keys($items));
    $item = reset($items);

    $this->assertSame('Results', $item->title);
    $this->assertSame('/results-2026', $item->url);
    $this->assertSame('en', $item->language);
    $this->assertSame('canvas_page:canvas_page', $item->metadata['type']);

    // The section's header slot renders before its content slot, whatever
    // order the field stores them in; root components follow in field order.
    $this->assertSame(
      "Results for the year\n\nRevenue\n\nRevenue rose to 1.2 billion.\n\nFull results\n\nWhat the chief executive said",
      $item->bodyHtml,
    );

    $unread = [
      'accent-panel',
      'card--highlight',
      'dashi-results-2026.jpg',
      '/investors/results',
      '7d0c4f5e-3b1a-4c2d-9e8f-6a5b4c3d2e1f',
      '<strong>',
    ];
    foreach ($unread as $hidden) {
      $this->assertStringNotContainsString($hidden, $item->bodyHtml, "Configuration a visitor never reads must not be indexed: $hidden");
    }
  }

  /**
   * Each translation is its own page, with its own text.
   */
  public function testOneItemPerTranslation(): void {
    $page = $this->createPage('Results', $this->englishText(), [], $this->spanishText());

    $items = $this->gatherPages();
    $id = 'canvas_page:' . $page->id();
    $this->assertSame([$id, $id . '-es'], array_keys($items));

    $this->assertSame('es', $items[$id . '-es']->language);
    $this->assertSame('Results (es)', $items[$id . '-es']->title);
    $this->assertStringContainsString('Resultados del año', $items[$id . '-es']->bodyHtml);
    $this->assertStringContainsString('Los ingresos subieron a 1200 millones.', $items[$id . '-es']->bodyHtml);
    $this->assertStringNotContainsString('Revenue', $items[$id . '-es']->bodyHtml);
    $this->assertStringNotContainsString('Resultados', $items[$id]->bodyHtml);

    // The queue hooks expect exactly the IDs the gatherer produces.
    $this->assertSame(array_keys($items), $this->container->get('scolta.content_gatherer')->itemIdsFor($page));
  }

  /**
   * Unpublished pages and unpublished translations are not gathered.
   */
  public function testUnpublishedExcluded(): void {
    $this->createPage('Draft results', $this->englishText(), ['status' => FALSE]);
    $page = $this->createPage('Results', $this->englishText(), [], $this->spanishText());
    $page->getTranslation('es')->setUnpublished()->save();

    $this->assertSame(['canvas_page:' . $page->id()], array_keys($this->gatherPages()));
  }

  /**
   * Only the live, published revision is gathered, never a workspace draft.
   */
  public function testWorkspaceDraftsExcluded(): void {
    $this->initializeWorkspacesModule();

    $page = $this->createPage('Results', $this->englishText());

    $this->switchToWorkspace('stage');
    $page = $this->container->get('entity_type.manager')->getStorage('canvas_page')->loadUnchanged($page->id());
    $page->set('components', $this->resultsTree(['heading' => 'Draft heading only in the workspace'] + $this->englishText()));
    $page->save();
    $this->createPage('Workspace only results', ['closing' => 'A page created in the workspace'] + $this->englishText());

    // The queue worker gathers from cron, in Live.
    $this->switchToLive();
    $items = $this->gatherPages();

    $this->assertSame(['canvas_page:' . $page->id()], array_keys($items));
    $body = reset($items)->bodyHtml;
    $this->assertStringContainsString('Results for the year', $body);
    $this->assertStringNotContainsString('Draft heading only in the workspace', $body);
  }

}
