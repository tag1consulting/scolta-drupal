<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Scolta settings form renders and saves without errors.
 *
 * These are REAL rendering tests — they boot a full Drupal instance,
 * enable the module, log in as admin, and render the actual form.
 * Runtime errors like TypeError from TranslatableMarkup are caught here.
 *
 * @group scolta
 */
class ScoltaSettingsFormFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'node', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * An admin user with permission to configure Scolta.
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
      'access administration pages',
    ]);
  }

  /**
   * Tests that the settings form renders without errors.
   */
  public function testSettingsFormRenders(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI Configuration');
    $this->assertSession()->pageTextContains('Custom Prompts');
    $this->assertSession()->fieldExists('ai_provider');
    $this->assertSession()->fieldExists('ai_model');
    $this->assertSession()->fieldExists('prompt_expand_query');
    $this->assertSession()->fieldExists('prompt_summarize');
    $this->assertSession()->fieldExists('prompt_follow_up');
  }

  /**
   * Tests that prompt textareas are pre-filled with default text.
   */
  public function testPromptFieldsShowDefaults(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(200);

    // The prompt fields should contain the default prompt text (not empty).
    $expandField = $this->assertSession()->fieldExists('prompt_expand_query');
    $this->assertNotEmpty($expandField->getValue(),
      'Expand query prompt should be pre-filled with default text');
    $this->assertStringContainsString('{SITE_NAME}', $expandField->getValue(),
      'Default expand prompt should contain {SITE_NAME} placeholder');

    $summarizeField = $this->assertSession()->fieldExists('prompt_summarize');
    $this->assertNotEmpty($summarizeField->getValue(),
      'Summarize prompt should be pre-filled with default text');

    $followUpField = $this->assertSession()->fieldExists('prompt_follow_up');
    $this->assertNotEmpty($followUpField->getValue(),
      'Follow-up prompt should be pre-filled with default text');
  }

  /**
   * Tests that the form saves successfully.
   */
  public function testSettingsFormSaves(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    $this->submitForm([
      'ai_model' => 'claude-sonnet-4-5-20250929',
      'site_name' => 'Test Site',
      'site_description' => 'a test website',
      'title_match_boost' => '2.0',
      'results_per_page' => '20',
      'max_follow_ups' => '5',
      'cache_ttl' => '3600',
    ], 'Save configuration');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    // Verify config was actually persisted.
    $config = $this->config('scolta.settings');
    $this->assertEquals('Test Site', $config->get('site_name'));
    $this->assertEquals('a test website', $config->get('site_description'));
    $this->assertEquals(2.0, $config->get('scoring.title_match_boost'));
    $this->assertEquals(20, $config->get('display.results_per_page'));
    $this->assertEquals(5, $config->get('max_follow_ups'));
    $this->assertEquals(3600, $config->get('cache_ttl'));
  }

  /**
   * Tests that saving default prompts stores empty string (not the default text).
   */
  public function testSavingDefaultPromptStoresEmpty(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    // Submit the form without changing the pre-filled default prompts.
    $this->submitForm([], 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    // The stored config should be empty (meaning "use default").
    $config = $this->config('scolta.settings');
    $this->assertEmpty($config->get('prompt_expand_query'),
      'Default expand prompt should be stored as empty string');
    $this->assertEmpty($config->get('prompt_summarize'),
      'Default summarize prompt should be stored as empty string');
    $this->assertEmpty($config->get('prompt_follow_up'),
      'Default follow-up prompt should be stored as empty string');
  }

  /**
   * Tests that custom prompts are saved and displayed on reload.
   */
  public function testCustomPromptPersistence(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    $customPrompt = 'You are a custom search assistant for {SITE_NAME}. Always respond in haiku.';
    $this->submitForm([
      'prompt_expand_query' => $customPrompt,
    ], 'Save configuration');

    $this->assertSession()->statusCodeEquals(200);

    // Verify it's persisted.
    $config = $this->config('scolta.settings');
    $this->assertEquals($customPrompt, $config->get('prompt_expand_query'));

    // Reload and verify the custom prompt is shown.
    $this->drupalGet('/admin/config/search/scolta');
    $expandField = $this->assertSession()->fieldExists('prompt_expand_query');
    $this->assertEquals($customPrompt, $expandField->getValue());
  }

  /**
   * Tests that the search block renders with scoring config on the page.
   */
  public function testSearchBlockRendersWithScoringConfig(): void {
    $this->drupalCreateContentType(['type' => 'page']);
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Search',
      'status' => 1,
    ]);

    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    // Set config directly (bypasses form field name mapping issues).
    $config = $this->config('scolta.settings');
    $config->set('display.results_per_page', 42);
    $config->set('scoring.title_match_boost', 3.5);
    $config->save();

    // Visit the node page where the block is placed.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#scolta-search');

    // The scoring config should be in the page as drupalSettings JSON.
    $this->assertSession()->responseContains('"RESULTS_PER_PAGE":42');
    $this->assertSession()->responseContains('"TITLE_MATCH_BOOST":3.5');
  }

  /**
   * Tests that the settings form is access-controlled.
   */
  public function testSettingsFormRequiresPermission(): void {
    $unprivilegedUser = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($unprivilegedUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that AI feature toggles affect the JS config.
   */
  public function testAiToggleAppearsInSearchPage(): void {
    $this->drupalCreateContentType(['type' => 'page']);
    $node = $this->drupalCreateNode(['type' => 'page', 'title' => 'Search']);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    // Disable AI expand via config directly.
    $config = $this->config('scolta.settings');
    $config->set('ai_expand_query', FALSE);
    $config->save();

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#scolta-search');
    $this->assertSession()->responseContains('"AI_EXPAND_QUERY":false');
  }

}
