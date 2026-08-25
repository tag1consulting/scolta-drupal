<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Executes the search-as-you-type settings surface end to end.
 *
 * SAYT lives in the vendored browser bundle; what this module has to get right
 * is the path from the admin form to window.scolta. Every step of that path has
 * failed silently before in this repo: a field that saves nothing, a saved
 * value the block never bridges, a bridged value the reloaded form does not
 * show. The bundle falls back to its own hardcoded defaults for an absent key,
 * and those defaults equal the shipped ones, so every one of those failures
 * looks like a working feature until someone changes a setting.
 *
 * The assertions therefore lean on the non-default direction: SAYT off, the
 * other suggestion action, numbers nothing would produce by accident.
 *
 * @group scolta
 */
class SaytSettingsFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'scolta_ui', 'search_api', 'node', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The ten config keys and their shipped defaults.
   */
  private const DEFAULTS = [
    'sayt_enabled' => TRUE,
    'sayt_min_chars' => 2,
    'sayt_debounce_ms' => 150,
    'sayt_max_suggestions' => 6,
    'sayt_recent_searches' => TRUE,
    'sayt_max_recent' => 3,
    'sayt_expand' => TRUE,
    'sayt_expand_per_minute' => 6,
    'sayt_expansion_delay_ms' => 500,
    'sayt_suggestion_action' => 'navigate',
  ];

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
    // The SAYT settings are on scolta_ui's form, behind its own permission.
    $this->adminUser = $this->drupalCreateUser([
      'administer scolta',
      'administer scolta ui',
      'access administration pages',
    ]);
    // ScoltaSearchBlock::build() attaches no drupalSettings at all when the
    // Pagefind index is missing, so the bridge assertions need a fake one.
    // Every write is checked: a fixture that half-succeeds surfaces later as
    // "the page carries no drupalSettings.scolta", which reads as a broken
    // bridge rather than as a broken fixture.
    $outputUri = \Drupal::config('scolta.settings')->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $realDir = \Drupal::service('stream_wrapper_manager')->getViaUri($outputUri)->realpath();
    $this->assertNotFalse($realDir, "Could not resolve {$outputUri} to a real path for the fake index fixture");

    $indexDir = $realDir . '/pagefind';
    $this->assertTrue(
      is_dir($indexDir) || mkdir($indexDir, 0777, TRUE),
      "Could not create the fake index directory {$indexDir}"
    );
    foreach (['pagefind.js' => '// fake index', 'pagefind-entry.json' => '{}'] as $name => $contents) {
      $this->assertNotFalse(
        file_put_contents($indexDir . '/' . $name, $contents),
        "Could not write the fake index file {$indexDir}/{$name}"
      );
    }
  }

  // -------------------------------------------------------------------
  // The form.
  // -------------------------------------------------------------------

  /**
   * The fieldset renders with every setting as its own field.
   */
  public function testSaytSectionRenders(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Search as you type');

    foreach (array_keys(self::DEFAULTS) as $key) {
      $this->assertSession()->fieldExists($key);
    }
  }

  /**
   * The fields open on the shipped defaults.
   */
  public function testFieldsShowTheShippedDefaults(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    $this->assertSame('2', $this->assertSession()->fieldExists('sayt_min_chars')->getValue());
    $this->assertSame('150', $this->assertSession()->fieldExists('sayt_debounce_ms')->getValue());
    $this->assertSame('6', $this->assertSession()->fieldExists('sayt_max_suggestions')->getValue());
    $this->assertSame('3', $this->assertSession()->fieldExists('sayt_max_recent')->getValue());
    $this->assertSame('6', $this->assertSession()->fieldExists('sayt_expand_per_minute')->getValue());
    $this->assertSame('500', $this->assertSession()->fieldExists('sayt_expansion_delay_ms')->getValue());
    $this->assertSession()->checkboxChecked('sayt_enabled');
    $this->assertSession()->checkboxChecked('sayt_recent_searches');
    $this->assertSession()->checkboxChecked('sayt_expand');
  }

  /**
   * Every value saves, and the reloaded form shows what was saved.
   *
   * The reload half is the admin-UI regression class this repo has shipped
   * before: a field that persists correctly but renders its default again on
   * the next page load reads to an admin as a save that did not take.
   */
  public function testEverySettingPersistsAndSurvivesAReload(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    $this->submitForm([
      'sayt_enabled' => TRUE,
      'sayt_min_chars' => '1',
      'sayt_debounce_ms' => '220',
      'sayt_max_suggestions' => '9',
      'sayt_recent_searches' => FALSE,
      'sayt_max_recent' => '7',
      'sayt_expand' => FALSE,
      'sayt_expand_per_minute' => '12',
      'sayt_expansion_delay_ms' => '750',
      'sayt_suggestion_action' => 'search',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('scolta_ui.settings');
    $this->assertTrue($config->get('sayt_enabled'));
    $this->assertSame(1, $config->get('sayt_min_chars'));
    $this->assertSame(220, $config->get('sayt_debounce_ms'));
    $this->assertSame(9, $config->get('sayt_max_suggestions'));
    $this->assertFalse($config->get('sayt_recent_searches'));
    $this->assertSame(7, $config->get('sayt_max_recent'));
    $this->assertFalse($config->get('sayt_expand'));
    $this->assertSame(12, $config->get('sayt_expand_per_minute'));
    $this->assertSame(750, $config->get('sayt_expansion_delay_ms'));
    $this->assertSame('search', $config->get('sayt_suggestion_action'));

    // The reloaded form shows the saved state, not the defaults again.
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSame('1', $this->assertSession()->fieldExists('sayt_min_chars')->getValue());
    $this->assertSame('220', $this->assertSession()->fieldExists('sayt_debounce_ms')->getValue());
    $this->assertSame('9', $this->assertSession()->fieldExists('sayt_max_suggestions')->getValue());
    $this->assertSame('7', $this->assertSession()->fieldExists('sayt_max_recent')->getValue());
    $this->assertSame('12', $this->assertSession()->fieldExists('sayt_expand_per_minute')->getValue());
    $this->assertSame('750', $this->assertSession()->fieldExists('sayt_expansion_delay_ms')->getValue());
    $this->assertSession()->checkboxNotChecked('sayt_recent_searches');
    $this->assertSession()->checkboxNotChecked('sayt_expand');
    $this->assertSession()->fieldValueEquals('sayt_suggestion_action', 'search');
  }

  /**
   * The off switch saves as FALSE.
   *
   * Its own test because it is the only setting whose whole purpose is the
   * false direction, and because a checkbox that silently saves TRUE is
   * indistinguishable from a working one on a default site.
   */
  public function testSaytCanBeTurnedOff(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->submitForm(['sayt_enabled' => FALSE], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertFalse($this->config('scolta_ui.settings')->get('sayt_enabled'));

    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->checkboxNotChecked('sayt_enabled');
  }

  // -------------------------------------------------------------------
  // The bridge into drupalSettings.
  // -------------------------------------------------------------------

  /**
   * A default install emits all ten browser keys with the documented values.
   */
  public function testDefaultsReachTheBrowser(): void {
    $emitted = $this->renderedScoltaSettings($this->placeBlockOnANode());

    $this->assertTrue($emitted['saytEnabled']);
    $this->assertSame(2, $emitted['saytMinChars']);
    $this->assertSame(150, $emitted['saytDebounceMs']);
    $this->assertSame(6, $emitted['saytMaxSuggestions']);
    $this->assertTrue($emitted['saytRecentSearches']);
    $this->assertSame(3, $emitted['saytMaxRecent']);
    $this->assertTrue($emitted['saytExpand']);
    $this->assertSame(6, $emitted['saytExpandPerMinute']);
    $this->assertSame(500, $emitted['saytExpansionDelayMs']);
    $this->assertSame('navigate', $emitted['saytSuggestionAction']);
  }

  /**
   * Saved values reach window.scolta, through the form rather than around it.
   *
   * Submitting the form rather than writing config directly is deliberate: it
   * is the only way a wrong form field name is caught. The settings page itself
   * carries no search block, so the bridge has to be asserted on a separate
   * page render.
   */
  public function testSavedValuesReachTheBrowser(): void {
    $node = $this->placeBlockOnANode();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->submitForm([
      'sayt_enabled' => FALSE,
      'sayt_min_chars' => '3',
      'sayt_debounce_ms' => '400',
      'sayt_max_suggestions' => '11',
      'sayt_recent_searches' => FALSE,
      'sayt_max_recent' => '5',
      'sayt_expand' => FALSE,
      'sayt_expand_per_minute' => '2',
      'sayt_expansion_delay_ms' => '900',
      'sayt_suggestion_action' => 'search',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $emitted = $this->renderedScoltaSettings($node);

    $this->assertFalse($emitted['saytEnabled'], 'The off switch must reach the browser as a literal false');
    $this->assertSame(3, $emitted['saytMinChars']);
    $this->assertSame(400, $emitted['saytDebounceMs']);
    $this->assertSame(11, $emitted['saytMaxSuggestions']);
    $this->assertFalse($emitted['saytRecentSearches']);
    $this->assertSame(5, $emitted['saytMaxRecent']);
    $this->assertFalse($emitted['saytExpand']);
    $this->assertSame(2, $emitted['saytExpandPerMinute']);
    $this->assertSame(900, $emitted['saytExpansionDelayMs']);
    $this->assertSame('search', $emitted['saytSuggestionAction']);
  }

  /**
   * An unrecognized suggestion action clamps to navigate rather than shipping.
   */
  public function testUnknownSuggestionActionClampsToNavigate(): void {
    $node = $this->placeBlockOnANode();
    $this->config('scolta_ui.settings')->set('sayt_suggestion_action', 'teleport')->save();

    $this->assertSame('navigate', $this->renderedScoltaSettings($node)['saytSuggestionAction']);
  }

  // -------------------------------------------------------------------
  // The update hook.
  // -------------------------------------------------------------------

  /**
   * The update adds every key a pre-SAYT site is missing.
   */
  public function testUpdateAddsAbsentKeys(): void {
    $this->simulatePreSaytSite();

    foreach (array_keys(self::DEFAULTS) as $key) {
      $this->assertNull(
        $this->config('scolta_ui.settings')->get($key),
        "Precondition: {$key} must start absent"
      );
    }

    $this->runSaytUpdate();

    $config = $this->config('scolta_ui.settings');
    foreach (self::DEFAULTS as $key => $value) {
      $this->assertSame($value, $config->get($key), "The update must add {$key}");
    }
  }

  /**
   * A value already set before the update survives it, including FALSE.
   *
   * The FALSE case is the one that matters: a site that turned SAYT off ahead
   * of the update must not have it switched back on, and a guard written with
   * empty() rather than an absence check would do exactly that.
   */
  public function testUpdateLeavesExistingValuesAlone(): void {
    $this->simulatePreSaytSite();
    $this->config('scolta_ui.settings')
      ->set('sayt_enabled', FALSE)
      ->set('sayt_max_suggestions', 20)
      ->set('sayt_suggestion_action', 'search')
      ->save();

    $this->runSaytUpdate();

    $config = $this->config('scolta_ui.settings');
    $this->assertFalse($config->get('sayt_enabled'), 'A deliberate opt-out must survive the update');
    $this->assertSame(20, $config->get('sayt_max_suggestions'));
    $this->assertSame('search', $config->get('sayt_suggestion_action'));

    // The keys that were absent still got their defaults.
    $this->assertSame(2, $config->get('sayt_min_chars'));
    $this->assertSame(150, $config->get('sayt_debounce_ms'));
  }

  /**
   * Running the update twice changes nothing the second time.
   */
  public function testUpdateIsIdempotent(): void {
    $this->simulatePreSaytSite();
    $this->runSaytUpdate();
    $after_first = $this->config('scolta_ui.settings')->getRawData();

    $this->runSaytUpdate();

    $this->assertSame(
      $after_first,
      $this->config('scolta_ui.settings')->getRawData(),
      'A second run of the update must be a no-op'
    );
  }

  /**
   * The update reports what it did.
   */
  public function testUpdateReturnsASummary(): void {
    $this->simulatePreSaytSite();

    $summary = (string) $this->runSaytUpdate();
    $this->assertStringContainsString('Search as you type', $summary);

    // Nothing left to do on the second pass, and it says so.
    $this->assertStringContainsString('already configured', (string) $this->runSaytUpdate());
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  /**
   * Removes the SAYT keys, leaving the config state of a pre-SAYT site.
   */
  private function simulatePreSaytSite(): void {
    $config = \Drupal::configFactory()->getEditable('scolta_ui.settings');
    foreach (array_keys(self::DEFAULTS) as $key) {
      $config->clear($key);
    }
    $config->save();
  }

  /**
   * Loads scolta.install and runs the SAYT update hook.
   *
   * @return mixed
   *   Whatever the hook returned.
   */
  private function runSaytUpdate() {
    \Drupal::moduleHandler()->loadInclude('scolta', 'install');
    $this->assertTrue(
      function_exists('scolta_update_10002'),
      'scolta.install must define scolta_update_10002()'
    );

    return scolta_update_10002();
  }

  /**
   * Creates a node carrying the search block.
   *
   * @return \Drupal\node\NodeInterface
   *   The node the block was placed on.
   */
  private function placeBlockOnANode() {
    $this->drupalCreateContentType(['type' => 'page']);
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Search',
      'status' => 1,
    ]);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    return $node;
  }

  /**
   * Renders a node page and returns its drupalSettings.scolta.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node carrying the search block.
   *
   * @return array
   *   The `scolta` key of the page's drupalSettings JSON.
   */
  private function renderedScoltaSettings($node): array {
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    $settings = $this->getDrupalSettings();
    $this->assertArrayHasKey(
      'scolta',
      $settings,
      'The rendered page carries no drupalSettings.scolta — check the fake index fixture in setUp().'
    );

    return $settings['scolta'];
  }

}
