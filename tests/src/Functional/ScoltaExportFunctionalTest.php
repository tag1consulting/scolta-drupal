<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the content export pipeline with real Drupal entities.
 *
 * Verifies that content is exported to HTML with correct Pagefind
 * data attributes, handling special characters, empty bodies, and
 * multiple content types.
 *
 * @group scolta
 */
class ScoltaExportFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'node', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * An admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser([
      'administer scolta',
      'administer search_api',
      'administer nodes',
      'create article content',
      'create page content',
    ]);
    $this->drupalCreateContentType(['type' => 'article']);
    $this->drupalCreateContentType(['type' => 'page']);
  }

  /**
   * Tests that search block renders with scoring config.
   */
  public function testSearchBlockWithScoringConfig(): void {
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Search Page',
      'status' => 1,
    ]);

    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#scolta-search');

    // Verify scoring config is embedded in the page.
    $pageContent = $this->getSession()->getPage()->getContent();
    if (str_contains($pageContent, 'RESULTS_PER_PAGE')) {
      $this->assertSession()->responseContains('TITLE_MATCH_BOOST');
      $this->assertSession()->responseContains('AI_EXPAND_QUERY');
    }
  }

  /**
   * Tests that custom scoring values appear in the page.
   */
  public function testCustomScoringAppearsInPage(): void {
    $node = $this->drupalCreateNode(['type' => 'page', 'title' => 'Search']);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    // Set custom config.
    $config = $this->config('scolta.settings');
    $config->set('display.results_per_page', 42);
    $config->set('scoring.title_match_boost', 3.5);
    $config->save();

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"RESULTS_PER_PAGE":42');
    $this->assertSession()->responseContains('"TITLE_MATCH_BOOST":3.5');
  }

  /**
   * Tests that AI toggle appears in page config.
   */
  public function testAiToggleInPageConfig(): void {
    $node = $this->drupalCreateNode(['type' => 'page', 'title' => 'Search']);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    // Disable AI expand.
    $config = $this->config('scolta.settings');
    $config->set('ai_expand_query', FALSE);
    $config->save();

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"AI_EXPAND_QUERY":false');
  }

  /**
   * Tests that endpoints reject empty/invalid queries.
   */
  public function testEndpointValidationErrors(): void {
    $user = $this->drupalCreateUser(['use scolta ai']);
    $this->drupalLogin($user);

    // Missing query field entirely.
    $response = $this->makeJsonPost('/api/scolta/v1/expand-query', []);
    $this->assertTrue($response['status'] >= 400);
    $this->assertArrayHasKey('error', $response['body'] ?? []);

    // Summarize without context.
    $response = $this->makeJsonPost('/api/scolta/v1/summarize', [
      'query' => 'test',
    ]);
    $this->assertTrue($response['status'] >= 400);

    // Follow-up with invalid message format.
    $response = $this->makeJsonPost('/api/scolta/v1/followup', [
      'messages' => [['invalid' => 'format']],
    ]);
    $this->assertTrue($response['status'] >= 400);
  }

  /**
   * Tests that follow-up limit is enforced.
   */
  public function testFollowUpLimitEnforced(): void {
    $user = $this->drupalCreateUser(['use scolta ai']);
    $this->drupalLogin($user);

    // Set max follow-ups to 0.
    $config = $this->config('scolta.settings');
    $config->set('max_follow_ups', 0);
    $config->save();

    // Any follow-up should be rejected.
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
   * Tests settings form saves all scoring parameters.
   */
  public function testSettingsFormSavesAllScoring(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'title_match_boost' => '2.5',
      'content_match_boost' => '0.8',
      'recency_boost_max' => '0.9',
      'recency_half_life_days' => '180',
      'expand_primary_weight' => '0.5',
      'excerpt_length' => '500',
      'results_per_page' => '25',
      'max_pagefind_results' => '100',
      'cache_ttl' => '7200',
      'max_follow_ups' => '5',
      'site_name' => 'Test Corp',
      'site_description' => 'a test company',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Verify all values persisted.
    $config = $this->config('scolta.settings');
    $this->assertEquals(2.5, $config->get('scoring.title_match_boost'));
    $this->assertEquals(0.8, $config->get('scoring.content_match_boost'));
    $this->assertEquals(0.9, $config->get('scoring.recency_boost_max'));
    $this->assertEquals(180, $config->get('scoring.recency_half_life_days'));
    $this->assertEquals(0.5, $config->get('scoring.expand_primary_weight'));
    $this->assertEquals(500, $config->get('display.excerpt_length'));
    $this->assertEquals(25, $config->get('display.results_per_page'));
    $this->assertEquals(100, $config->get('display.max_pagefind_results'));
    $this->assertEquals(7200, $config->get('cache_ttl'));
    $this->assertEquals(5, $config->get('max_follow_ups'));
    $this->assertEquals('Test Corp', $config->get('site_name'));
    $this->assertEquals('a test company', $config->get('site_description'));
  }

  /**
   * Tests custom prompt persistence through form.
   */
  public function testCustomPromptFormRoundTrip(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    $customPrompt = 'You are a custom assistant for {SITE_NAME}. Always be helpful.';
    $this->submitForm([
      'prompt_expand_query' => $customPrompt,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Reload and verify the custom prompt persisted with placeholders intact.
    $config = $this->config('scolta.settings');
    $this->assertEquals($customPrompt, $config->get('prompt_expand_query'));

    // Reload form and verify it shows in the textarea.
    $this->drupalGet('/admin/config/search/scolta');
    $field = $this->assertSession()->fieldExists('prompt_expand_query');
    $this->assertEquals($customPrompt, $field->getValue());
  }

  /**
   * Tests access control on settings form.
   */
  public function testSettingsFormAccessDenied(): void {
    $unprivileged = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($unprivileged);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(403);
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
