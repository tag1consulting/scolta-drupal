<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * The AI features are advertised to exactly the visitors who may use them.
 *
 * Before scolta.ai_access the two decisions were taken in places that could
 * not see each other. 'use scolta ai' was a route requirement and nothing
 * else, so ScoltaSearchBlock::build() emitted AI_EXPAND_QUERY and
 * AI_SUMMARIZE from site config for every visitor: an anonymous one, who
 * does not hold the permission on a default install, was handed the full AI
 * search and got a 403 from each endpoint it then called — swallowed by
 * scolta.js into a console warning, so the search looked merely slow. The
 * config flags had the mirror-image gap: they gated the browser and the
 * handler from the same values with nothing holding the two readings
 * together.
 *
 * These tests pin both directions of the fixed contract: what the page
 * claims and what the endpoint does agree, for the permission and for the
 * config flags.
 *
 * @group scolta
 */
class AiAccessFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'scolta_ui', 'search_api', 'node', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The AI endpoints, keyed by the feature each serves.
   */
  private const ENDPOINTS = [
    'expand' => '/api/scolta/v1/expand-query',
    'summarize' => '/api/scolta/v1/summarize',
    'follow_up' => '/api/scolta/v1/followup',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // build() attaches no drupalSettings at all while the Pagefind index is
    // missing, so without this fixture every assertion below would be made
    // against an empty block.
    $outputUri = \Drupal::config('scolta.settings')->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $realDir = \Drupal::service('stream_wrapper_manager')->getViaUri($outputUri)->realpath();
    if ($realDir !== FALSE) {
      @mkdir($realDir . '/pagefind', 0777, TRUE);
      file_put_contents($realDir . '/pagefind/pagefind.js', '// fake index');
      file_put_contents($realDir . '/pagefind/pagefind-entry.json', '{}');
    }

    $this->drupalCreateContentType(['type' => 'page']);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);
  }

  /**
   * A visitor holding the permission is told both features exist.
   */
  public function testPermittedVisitorGetsBothFeatures(): void {
    // scolta_install() grants 'use scolta ai' to the authenticated role.
    $this->drupalLogin($this->drupalCreateUser());

    $scoring = $this->renderedScoring();
    $this->assertTrue($scoring['AI_EXPAND_QUERY'], 'A permitted visitor must be offered query expansion');
    $this->assertTrue($scoring['AI_SUMMARIZE'], 'A permitted visitor must be offered the AI overview');

    foreach (self::ENDPOINTS as $feature => $path) {
      $this->assertNotSame(
        403,
        $this->postJson($path),
        "A permitted visitor must reach the {$feature} endpoint"
      );
    }
  }

  /**
   * A visitor without the permission is told neither feature exists.
   *
   * The anonymous role, which a default install deliberately leaves without
   * 'use scolta ai'. This is the case that used to render an AI search UI
   * whose every request came back 403.
   */
  public function testVisitorWithoutThePermissionGetsNeitherFeature(): void {
    $this->assertFalse(
      \Drupal::entityTypeManager()->getStorage('user_role')
        ->load(RoleInterface::ANONYMOUS_ID)
        ->hasPermission('use scolta ai'),
      "This test's premise is that anonymous does not hold 'use scolta ai' on a default install"
    );

    $scoring = $this->renderedScoring();
    $this->assertFalse($scoring['AI_EXPAND_QUERY'], 'Query expansion must not be advertised to a visitor who cannot use it');
    $this->assertFalse($scoring['AI_SUMMARIZE'], 'The AI overview must not be advertised to a visitor who cannot use it');

    foreach (self::ENDPOINTS as $feature => $path) {
      $this->assertSame(
        403,
        $this->postJson($path),
        "The {$feature} endpoint must refuse a visitor without the permission"
      );
    }
  }

  /**
   * A feature switched off in config keeps answering exactly as it did.
   *
   * The block stops advertising it, which it already did. The endpoint still
   * answers AiEndpointHandler's 404 rather than a 403 from routing: the
   * config flags say what the site offers, not who may ask, so restating
   * them in the access rule would turn a documented response into a refusal
   * on a site that has decorated nothing.
   */
  public function testFeatureDisabledInConfigIsUnchangedOnTheRoute(): void {
    $this->config('scolta_ui.settings')
      ->set('ai_expand_query', FALSE)
      ->set('ai_summarize', FALSE)
      ->save();

    $this->drupalLogin($this->drupalCreateUser());

    $scoring = $this->renderedScoring();
    $this->assertFalse($scoring['AI_EXPAND_QUERY']);
    $this->assertFalse($scoring['AI_SUMMARIZE']);

    foreach (['expand' => 'expand', 'summarize' => 'summarize'] as $feature => $_) {
      $this->assertSame(
        404,
        $this->postJson(self::ENDPOINTS[$feature]),
        "The {$feature} endpoint must keep answering 404 for a switched-off feature, not 403"
      );
    }
  }

  /**
   * The scoring config a page carrying the search block emits.
   *
   * @return array
   *   drupalSettings.scolta.scoring.
   */
  private function renderedScoring(): array {
    $node = $this->drupalCreateNode(['type' => 'page', 'status' => 1]);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    $settings = $this->getDrupalSettings();
    $this->assertArrayHasKey('scolta', $settings, 'The page carries no drupalSettings.scolta');

    return $settings['scolta']['scoring'];
  }

  /**
   * POSTs a JSON body and returns the status code.
   *
   * The body is deliberately not a valid request for any one endpoint: these
   * assertions are about whether the request is refused before the
   * controller, and a 400 from the controller answers that as well as a 200
   * would.
   *
   * @param string $path
   *   The endpoint path.
   *
   * @return int
   *   The HTTP status code.
   */
  private function postJson(string $path): int {
    $session = $this->getSession();
    $session->getDriver()->getClient()->request(
      'POST',
      $this->getAbsoluteUrl($path),
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['query' => 'test', 'context' => 'test'])
    );

    return $session->getStatusCode();
  }

}
