<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Scolta API endpoints with real HTTP requests.
 *
 * @group scolta
 */
class ScoltaEndpointFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'node', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Creates a minimal fake Pagefind index so the search block renders.
   *
   * ScoltaSearchBlock::build() returns empty when the pagefind index is
   * missing. Tests that place the block and assert on its HTML need a real
   * (but empty) index file at the configured output location.
   */
  protected function createFakeIndex(): void {
    $settings = \Drupal::config('scolta.settings');
    $outputUri = $settings->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $wrappers = \Drupal::service('stream_wrapper_manager');
    $realDir = $wrappers->getViaUri($outputUri)->realpath();
    if ($realDir !== FALSE) {
      @mkdir($realDir . '/pagefind', 0777, TRUE);
      file_put_contents($realDir . '/pagefind/pagefind.js', '// fake index');
      file_put_contents($realDir . '/pagefind/pagefind-entry.json', '{}');
    }
  }

  /**
   * GET requests to POST-only endpoints must return 4xx, not 200 or 500.
   *
   * Verifies method enforcement on the AI API routes. These are POST-only;
   * a GET by any user (anonymous or authenticated) must be rejected with
   * 403, 404, or 405 — never 200 or 500.
   */
  public function testEndpointsRequirePermission(): void {
    $endpoints = [
      '/api/scolta/v1/expand-query',
      '/api/scolta/v1/summarize',
      '/api/scolta/v1/followup',
    ];

    foreach ($endpoints as $endpoint) {
      $this->drupalGet($endpoint);
      $statusCode = $this->getSession()->getStatusCode();
      $this->assertTrue(
        $statusCode >= 400 && $statusCode < 500,
        "GET to POST-only endpoint {$endpoint} should return 4xx, got {$statusCode}"
      );
    }
  }

  /**
   * The AI endpoints are closed to anonymous traffic out of the box.
   *
   * hook_install() grants 'use scolta ai' to the authenticated role only.
   * These endpoints make cost-bearing LLM calls, so serving them to
   * unauthenticated visitors is a decision a site makes rather than a default
   * it inherits: a site that wants it grants the permission to the anonymous
   * role at Administration › People › Permissions.
   */
  public function testAiEndpointsDenyAnonymousByDefault(): void {
    $endpoints = [
      '/api/scolta/v1/expand-query',
      '/api/scolta/v1/summarize',
      '/api/scolta/v1/followup',
    ];

    foreach ($endpoints as $endpoint) {
      $response = $this->makeJsonPost($endpoint, []);
      $this->assertEquals(
        403, $response['status'],
        "Anonymous POST to {$endpoint} must be forbidden — 'use scolta ai' is not granted to anonymous at install"
      );
    }
  }

  /**
   * Logged-in visitors reach the AI endpoints out of the box.
   *
   * The other half of the install-time grant: authenticated AI search is
   * intended, so the permission check must pass without any admin action. A
   * POST with an invalid body returns a 4xx of its own, so the assertion is
   * specifically about it not being 403.
   */
  public function testAiEndpointsAllowAuthenticatedByDefault(): void {
    $this->drupalLogin($this->drupalCreateUser([]));

    foreach ([
      '/api/scolta/v1/expand-query',
      '/api/scolta/v1/summarize',
      '/api/scolta/v1/followup',
    ] as $endpoint) {
      $response = $this->makeJsonPost($endpoint, []);
      $this->assertNotEquals(
        403, $response['status'],
        "Authenticated POST to {$endpoint} should not be forbidden — 'use scolta ai' is granted at install"
      );
      $this->assertNotEquals(
        500, $response['status'],
        "Authenticated POST to {$endpoint} must not crash"
      );
    }
  }

  /**
   * Tests that the expand endpoint validates input.
   */
  public function testExpandEndpointValidation(): void {
    $user = $this->drupalCreateUser(['use scolta ai']);
    $this->drupalLogin($user);

    // Empty query should fail.
    $response = $this->makeJsonPost('/api/scolta/v1/expand-query', ['query' => '']);
    $this->assertTrue(
      $response['status'] >= 400,
      'Empty query should be rejected'
    );

    // Too-long query should fail.
    $response = $this->makeJsonPost('/api/scolta/v1/expand-query', [
      'query' => str_repeat('a', 501),
    ]);
    $this->assertTrue(
      $response['status'] >= 400,
      'Query over 500 chars should be rejected'
    );
  }

  /**
   * Tests that the summarize endpoint validates input.
   */
  public function testSummarizeEndpointValidation(): void {
    $user = $this->drupalCreateUser(['use scolta ai']);
    $this->drupalLogin($user);

    // A query with no context should fail.
    $response = $this->makeJsonPost('/api/scolta/v1/summarize', ['query' => 'test']);
    $this->assertTrue(
      $response['status'] >= 400,
      'Summarize with no context should be rejected'
    );
  }

  /**
   * Tests that the follow-up endpoint validates input.
   */
  public function testFollowUpEndpointValidation(): void {
    $user = $this->drupalCreateUser(['use scolta ai']);
    $this->drupalLogin($user);

    // A message array missing the expected shape should fail.
    $response = $this->makeJsonPost('/api/scolta/v1/followup', [
      'messages' => [['invalid' => 'format']],
    ]);
    $this->assertTrue(
      $response['status'] >= 400,
      'Malformed follow-up message shape should be rejected'
    );
  }

  /**
   * The max_follow_ups quota rejects a follow-up once exhausted.
   *
   * A quota of 0 means every follow-up is over budget, so this is the
   * cheapest way to observe the config-driven limit taking effect without
   * needing to first exhaust a real conversation history.
   */
  public function testFollowUpLimitEnforced(): void {
    $user = $this->drupalCreateUser(['use scolta ai']);
    $this->drupalLogin($user);

    $this->config('scolta.settings')->set('max_follow_ups', 0)->save();

    $response = $this->makeJsonPost('/api/scolta/v1/followup', [
      'messages' => [
        ['role' => 'user', 'content' => 'initial question'],
        ['role' => 'assistant', 'content' => 'initial answer'],
        ['role' => 'user', 'content' => 'follow-up'],
      ],
    ]);
    $this->assertEquals(429, $response['status']);
  }

  /**
   * Tests that the search block renders on a page.
   */
  public function testSearchBlockRenders(): void {
    $this->createFakeIndex();
    $this->drupalCreateContentType(['type' => 'page']);
    $this->drupalCreateNode(['type' => 'page', 'title' => 'Search Page']);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    $this->drupalGet('/node/1');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#scolta-search');
    $this->assertSession()->responseContains('scolta.js');
    $this->assertSession()->responseContains('pagefindPath');
  }

  /**
   * Make a JSON POST request and return status + decoded body.
   *
   * @param string $path
   *   The URL path.
   * @param array $data
   *   The POST body data.
   *
   * @return array
   *   Array with 'status' and 'body' keys.
   */
  protected function makeJsonPost(string $path, array $data): array {
    $url = $this->getAbsoluteUrl($path);
    $session = $this->getSession();

    $session->getDriver()->getClient()->request(
      'POST',
      $url,
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($data),
    );

    return [
      'status' => $session->getStatusCode(),
      'body' => json_decode($session->getPage()->getContent(), TRUE),
    ];
  }

}
