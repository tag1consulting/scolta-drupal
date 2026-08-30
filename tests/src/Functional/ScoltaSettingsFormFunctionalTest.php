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
    // Create a minimal fake index so ScoltaSearchBlock renders the full UI.
    $outputUri = \Drupal::config('scolta.settings')->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $realDir = \Drupal::service('stream_wrapper_manager')->getViaUri($outputUri)->realpath();
    if ($realDir !== FALSE) {
      @mkdir($realDir . '/pagefind', 0777, TRUE);
      file_put_contents($realDir . '/pagefind/pagefind.js', '// fake index');
      file_put_contents($realDir . '/pagefind/pagefind-entry.json', '{}');
    }
  }

  /**
   * Tests that the form saves successfully.
   *
   * Also covers the plain rendering/field-existence and default-prefill
   * checks that used to be separate tests: a save that reaches every one of
   * these fields cannot pass if the form failed to render them first.
   */
  public function testSettingsFormSaves(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI Configuration');
    $this->assertSession()->pageTextContains('Custom Prompts');

    // Prompt fields are pre-filled with default text before any save.
    $expandField = $this->assertSession()->fieldExists('prompt_expand_query');
    $this->assertStringContainsString('{SITE_NAME}', $expandField->getValue(),
      'Default expand prompt should contain {SITE_NAME} placeholder');
    $this->assertNotEmpty($this->assertSession()->fieldExists('prompt_summarize')->getValue());
    $this->assertNotEmpty($this->assertSession()->fieldExists('prompt_follow_up')->getValue());

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
   * Malformed recency-curve JSON must produce a validation error.
   *
   * Pre-fix, submitForm() ran json_decode(...) ?: [] and silently wiped the
   * input without telling the admin anything was wrong.
   */
  public function testMalformedRecencyCurveJsonIsRejected(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    $this->submitForm([
      'recency_curve' => 'this is not json',
    ], 'Save configuration');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The custom recency curve must be a JSON array');
    $this->assertSession()->pageTextNotContains('The configuration options have been saved.');
  }

  /**
   * A key|value line without a pipe must produce a validation error.
   *
   * Pre-fix, parseKeyValueLines() silently dropped pipe-less lines on save.
   */
  public function testPipelessKeyValueLineIsRejected(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    $this->submitForm([
      'filter_field_descriptions' => "topic|Subject area\nthis line has no pipe",
    ], 'Save configuration');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('missing the "|" separator');
    $this->assertSession()->pageTextNotContains('The configuration options have been saved.');

    // A valid submission still saves.
    $this->drupalGet('/admin/config/search/scolta');
    $this->submitForm([
      'filter_field_descriptions' => 'topic|Subject area',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertEquals(
      ['topic' => 'Subject area'],
      $this->config('scolta.settings')->get('filter_field_descriptions')
    );
  }

  /**
   * settings.php $config['scolta.settings'] overrides must reach AI traffic.
   *
   * Pre-fix, ScoltaAiService::buildConfig() read getRawData(), so the
   * standard way of keeping keys out of exported config silently did not
   * apply to the AI pipeline.
   */
  public function testSettingsPhpOverrideAppliesToAiConfig(): void {
    $this->writeSettings([
      'config' => [
        'scolta.settings' => [
          'site_description' => (object) [
            'value' => 'overridden-by-settings-php',
            'required' => TRUE,
          ],
        ],
      ],
    ]);
    $this->rebuildAll();

    $config = $this->container->get('scolta.ai_service')->getConfig();
    $this->assertSame('overridden-by-settings-php', $config->siteDescription,
      'The settings.php config override must apply to the AI service config');
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
   * Tests the sub-word guard denylist field saves and renders its value.
   *
   * Release-gate render assertion for the scolta-php#156 follow-up: the field
   * must round-trip a saved value through the UI.
   */
  public function testSubwordDenyListPersistence(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    // The field exists and is empty by default.
    $this->assertSession()->fieldExists('expand_subword_deny_list');

    $this->submitForm([
      'expand_subword_deny_list' => 'Hot, Easy',
    ], 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    // Persisted as a lowercase token array.
    $config = $this->config('scolta.settings');
    $this->assertEquals(['hot', 'easy'], $config->get('scoring.expand_subword_deny_list'));

    // Reload and verify the saved value is rendered (comma-joined).
    $this->drupalGet('/admin/config/search/scolta');
    $field = $this->assertSession()->fieldExists('expand_subword_deny_list');
    $this->assertEquals('hot, easy', $field->getValue());
  }

  /**
   * Tests the expansion combine-mode and per-term top-K fields persist.
   *
   * Release-gate render assertion for scolta-php#170/#179: the combine mode must
   * round-trip a saved value through the UI and default to the historical
   * relevance-union behavior. The per-term top-K is no longer configurable (it
   * is locked at 3 inside scolta-php), so its form field must be gone.
   */
  public function testExpansionCombineModePersistence(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    // The combine-mode field exists and defaults to the historical behavior.
    $modeField = $this->assertSession()->fieldExists('expansion_combine_mode');
    $this->assertEquals('relevance_union', $modeField->getValue());

    // The locked per-term top-K knob is no longer exposed as a form field.
    $this->assertSession()->fieldNotExists('expansion_per_term_top_k');

    $this->submitForm([
      'expansion_combine_mode' => 'round_robin',
    ], 'Save configuration');
    $this->assertSession()->statusCodeEquals(200);

    // Persisted with the selected mode; no per-term top-K is written.
    $config = $this->config('scolta.settings');
    $this->assertEquals('round_robin', $config->get('scoring.expansion_combine_mode'));
    $this->assertNull($config->get('scoring.expansion_per_term_top_k'));

    // Reload and verify the saved value is rendered.
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertEquals('round_robin', $this->assertSession()->fieldExists('expansion_combine_mode')->getValue());
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
   * The hide-empty-facets opt-out must survive the form and reach the browser.
   *
   * Deliberately a full form round-trip rather than the config-set shortcut the
   * block tests above use, and deliberately tests the FALSE direction. The
   * browser default is to hide, so a bridge that drops the value entirely still
   * looks correct in the on state: only unticking the box can prove the saved
   * value is honoured end to end. This is exactly the class of bug that once
   * shipped an admin field ignoring what it saved.
   *
   * Both halves matter. Submitting the form and asserting on config catches a
   * form-field-name typo; rendering a node page and asserting on drupalSettings
   * catches a missing bridge in ScoltaSearchBlock::build(). The settings page
   * carries no search block, so asserting on the form response alone would find
   * no drupalSettings.scolta at all.
   */
  public function testHideEmptyFacetsOptOutReachesTheBrowser(): void {
    $this->drupalCreateContentType(['type' => 'page']);
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Search',
      'status' => 1,
    ]);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    // Half one: the form saves the unticked checkbox as FALSE.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->submitForm(['hide_empty_facets' => FALSE], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertFalse($this->config('scolta.settings')->get('hide_empty_facets'));

    // Half two: the saved FALSE reaches window.scolta on a rendered page.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"hideEmptyFacets":false');
  }

  /**
   * A chosen facet mode must save and reach window.scolta.
   *
   * The two halves are asserted together for the same reason the
   * hideEmptyFacets pair is: a setting that saves but never reaches the payload
   * looks correct in the admin UI and does nothing on the site. Both
   * 'deferred' and 'disabled' are checked in one pass — 'disabled' earns its
   * own case because it is the mode that suppresses the filter sidebar
   * entirely, so a clamp bug there would be invisible in the admin UI and
   * would leave the site rendering facets its owner turned off.
   */
  public function testFacetModeSelectionReachesTheBrowser(): void {
    $this->drupalCreateContentType(['type' => 'page']);
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Search',
      'status' => 1,
    ]);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->submitForm(['facet_mode' => 'deferred'], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSame('deferred', $this->config('scolta.settings')->get('facet_mode'));

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"facetMode":"deferred"');

    $this->drupalGet('/admin/config/search/scolta');
    $this->submitForm(['facet_mode' => 'disabled'], 'Save configuration');
    $this->assertSame('disabled', $this->config('scolta.settings')->get('facet_mode'));

    $this->drupalGet($node->toUrl());
    $this->assertSession()->responseContains('"facetMode":"disabled"');
  }

  /**
   * The default install state must emit facetMode as 'eager'.
   *
   * Pins the default direction. An absent key reads as 'eager' in the bundle
   * too, so without this a bridge emitting nothing would look identical.
   */
  public function testFacetModeDefaultRendersEager(): void {
    $this->drupalCreateContentType(['type' => 'page']);
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Search',
      'status' => 1,
    ]);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"facetMode":"eager"');
  }

  /**
   * The default install state must emit hideEmptyFacets as TRUE.
   *
   * Pins the default direction so the key is always present in the payload.
   * An absent key is read by scolta.js as "hide" (only a literal false
   * disables it), which is the same visible behavior as true — so without this
   * assertion a bridge that emitted nothing at all would look identical.
   */
  public function testHideEmptyFacetsDefaultRendersTrue(): void {
    $this->drupalCreateContentType(['type' => 'page']);
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Search',
      'status' => 1,
    ]);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"hideEmptyFacets":true');
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
   * Stores fake Amazee credentials so isAmazeeActive() returns TRUE.
   */
  protected function activateAmazee(): void {
    \Drupal::state()->set('scolta.amazee.credentials', [
      'litellm_token' => 'amazee-test-token',
      'litellm_api_url' => 'https://api.amazee.example.com',
      'region' => 'us-east-1',
    ]);
  }

  /**
   * Tests the field shows the saved provider when Amazee is active (#125).
   *
   * Regression for #125: an active Amazee trial must not override an
   * explicitly-saved provider in the field's pre-selected value.
   */
  public function testProviderFieldReflectsSavedValueWhenAmazeeActive(): void {
    $this->activateAmazee();
    $this->config('scolta.settings')->set('ai_provider', 'anthropic')->save();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(200);

    // The saved provider (anthropic) must be the selected option, not amazee.
    $field = $this->assertSession()->fieldExists('ai_provider');
    $this->assertSame('anthropic', $field->getValue(),
      'Provider field must show the saved provider (anthropic), not amazee.');
    $this->assertSession()->optionExists('ai_provider', 'amazee');
  }

  /**
   * With nothing saved, a stored connection does not select the provider.
   *
   * The field used to fall back to detecting stored Amazee.ai credentials
   * when no provider had been saved. That is the auto-selection the opt-in
   * rule removes: the managed gateway governs AI traffic only when somebody
   * selected it, so a connection existing must not preselect it.
   */
  public function testProviderFieldDoesNotSelectAmazeeFromStoredCredentials(): void {
    $this->activateAmazee();
    // Ensure no provider has been explicitly saved.
    $this->config('scolta.settings')->clear('ai_provider')->save();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    $field = $this->assertSession()->fieldExists('ai_provider');
    $this->assertSame('', $field->getValue(),
      'With no saved provider the field must sit on the placeholder: stored '
      . 'credentials must not preselect Amazee, and nothing else is selected '
      . 'either, because there is no default provider.');
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
