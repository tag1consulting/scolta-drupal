<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;

/**
 * Runs scolta_update_10007() against a pre-split site and checks the result.
 *
 * The partition itself is proved statically over the schemas, in
 * \Drupal\scolta\Tests\ScoltaConfigPartitionTest. What cannot be proved that
 * way is what the update does to a site's stored values, which is what an
 * upgrading operator actually cares about. Four properties, on a fixture whose
 * values are all deliberately non-default so a migration that silently wrote
 * defaults would be caught:
 *
 * - One home. Each moved key is gone from scolta.settings and present in
 *   scolta_ui.settings; each build key is the other way round.
 * - Value fidelity. Every moved value equals what the fixture stored, so
 *   nothing was re-defaulted on the way across.
 * - Idempotence. Running the update a second time produces config identical
 *   to running it once.
 * - Access preserved. A role that could administer Scolta before can still
 *   reach the settings screen afterwards, through the new permission.
 *
 * The fixture is built by writing the pre-split shape back into
 * scolta.settings — a single object holding both halves — which is exactly
 * the state a site updating into this release is in.
 *
 * @group scolta
 */
class ConfigSplitUpdateFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * The fixture writes the pre-split object: one scolta.settings holding both
   * halves. Those keys are, correctly, no longer in scolta's schema, so the
   * strict checker refuses the save and the migration under test never runs.
   * Turning it off is the point — this class exists to exercise config that
   * the shipped schema deliberately no longer describes.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Query-time values, none of them the shipped default.
   *
   * Nested keys included: a migration that moved only scalars would pass a
   * flat fixture and lose a site's whole scoring configuration.
   */
  private const FRONTEND_FIXTURE = [
    'preset' => 'documentation',
    'ai_provider' => 'openai',
    'ai_model' => 'gpt-4o-mini',
    'ai_expand_query' => FALSE,
    'ai_summarize' => FALSE,
    'ai_languages' => ['fr', 'de'],
    'max_follow_ups' => 7,
    'site_name' => 'Fixture Site',
    'site_description' => 'a fixture',
    'sortable_field_descriptions' => ['date' => 'Publication date'],
    'filter_field_descriptions' => ['topic' => 'Subject area'],
    'cache_ttl' => 99,
    'show_attribution' => TRUE,
    'hide_empty_facets' => FALSE,
    'facet_mode' => 'deferred',
    'sayt_enabled' => FALSE,
    'sayt_min_chars' => 4,
    'prompt_expand_query' => 'a custom expand prompt',
    'scoring' => ['title_match_boost' => 9.5, 'language' => 'fr', 'custom_stop_words' => ['le', 'la']],
    'display' => ['excerpt_length' => 42, 'results_per_page' => 3],
    'flood' => ['ai_ip_limit' => 11, 'ai_ip_window' => 22],
  ];

  /**
   * Build-time values, also non-default, which must not move.
   */
  private const BACKEND_FIXTURE = [
    'indexer' => 'binary',
    'sortable_fields' => ['date', 'title'],
    'filter_fields' => ['topic'],
    'field_mappings' => ['sortable' => ['field_date' => 'date'], 'filters' => []],
  ];

  /**
   * Puts the site into the single-object state that predates the split.
   */
  private function simulatePreSplitSite(): void {
    // The frontend module and its config object do not exist before the
    // update; a fixture that left them in place would not be testing a
    // migration.
    if (\Drupal::moduleHandler()->moduleExists('scolta_ui')) {
      \Drupal::service('module_installer')->uninstall(['scolta_ui']);
    }
    \Drupal::configFactory()->getEditable('scolta_ui.settings')->delete();

    $config = \Drupal::configFactory()->getEditable('scolta.settings');
    foreach (self::FRONTEND_FIXTURE + self::BACKEND_FIXTURE as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Runs the update the way `drush updatedb` would.
   */
  private function runSplitUpdate() {
    \Drupal::moduleHandler()->loadInclude('scolta', 'install');
    $this->assertTrue(
      function_exists('scolta_update_10007'),
      'scolta.install must define scolta_update_10007()'
    );

    $message = scolta_update_10007();
    // Enabling a module rebuilds the container; without this the assertions
    // below read the config factory the update no longer writes to.
    $this->rebuildContainer();

    return $message;
  }

  // -------------------------------------------------------------------

  /**
   * The update enables the frontend, so an upgrading site keeps its search.
   */
  public function testUpdateEnablesTheFrontendModule(): void {
    $this->simulatePreSplitSite();
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('scolta_ui'));

    $this->runSplitUpdate();

    $this->assertTrue(
      \Drupal::moduleHandler()->moduleExists('scolta_ui'),
      'Without scolta_ui an upgraded site has no block, no endpoints and no settings form'
    );
  }

  /**
   * Every key ends up in exactly one object.
   */
  public function testEachKeyHasExactlyOneHomeAfterwards(): void {
    $this->simulatePreSplitSite();
    $this->runSplitUpdate();

    $backend = $this->config('scolta.settings');
    $frontend = $this->config('scolta_ui.settings');

    foreach (array_keys(self::FRONTEND_FIXTURE) as $key) {
      $this->assertNull($backend->get($key), "Moved key '{$key}' must be cleared from scolta.settings");
      $this->assertNotNull($frontend->get($key), "Moved key '{$key}' must be present in scolta_ui.settings");
    }

    foreach (array_keys(self::BACKEND_FIXTURE) as $key) {
      $this->assertNotNull($backend->get($key), "Build key '{$key}' must stay in scolta.settings");
      $this->assertNull($frontend->get($key), "Build key '{$key}' must not appear in scolta_ui.settings");
    }
  }

  /**
   * Values cross unchanged; nothing is quietly re-defaulted.
   */
  public function testMovedValuesAreCarriedVerbatim(): void {
    $this->simulatePreSplitSite();
    $this->runSplitUpdate();

    $frontend = $this->config('scolta_ui.settings');
    foreach (self::FRONTEND_FIXTURE as $key => $value) {
      $this->assertSame($value, $frontend->get($key), "'{$key}' must arrive with the value the site had");
    }

    $backend = $this->config('scolta.settings');
    foreach (self::BACKEND_FIXTURE as $key => $value) {
      $this->assertSame($value, $backend->get($key), "'{$key}' must be left exactly as it was");
    }
  }

  /**
   * Both origins initialize to this site, so behaviour does not change.
   */
  public function testBothOriginsInitializeToLocal(): void {
    $this->simulatePreSplitSite();
    $this->runSplitUpdate();

    $frontend = $this->config('scolta_ui.settings');
    $this->assertSame('<local>', $frontend->get('index_origin'));
    $this->assertSame('<local>', $frontend->get('ai_origin'));
  }

  /**
   * A key the pre-split site never had lands on its documented default.
   */
  public function testAbsentKeysFallToTheInstallDefault(): void {
    $this->simulatePreSplitSite();
    // A site that never ran the SAYT update has no such key at all.
    \Drupal::configFactory()->getEditable('scolta.settings')->clear('sayt_debounce_ms')->save();

    $this->runSplitUpdate();

    $this->assertSame(
      150,
      $this->config('scolta_ui.settings')->get('sayt_debounce_ms'),
      'An absent key must be left at the shipped default rather than written as NULL'
    );
  }

  /**
   * Running the update twice yields config identical to running it once.
   */
  public function testUpdateIsIdempotent(): void {
    $this->simulatePreSplitSite();
    $this->runSplitUpdate();

    $backendAfterFirst = $this->config('scolta.settings')->getRawData();
    $frontendAfterFirst = $this->config('scolta_ui.settings')->getRawData();

    $this->runSplitUpdate();

    $this->assertSame($backendAfterFirst, $this->config('scolta.settings')->getRawData());
    $this->assertSame($frontendAfterFirst, $this->config('scolta_ui.settings')->getRawData());
  }

  /**
   * A role that could administer Scolta still can.
   */
  public function testTheAdminPermissionIsCarriedAcross(): void {
    $this->simulatePreSplitSite();

    $roleId = $this->drupalCreateRole(['administer scolta'], 'scolta_admin');
    $this->assertTrue(Role::load($roleId)->hasPermission('administer scolta'));

    $this->runSplitUpdate();

    $role = Role::load($roleId);
    $this->assertTrue(
      $role->hasPermission('administer scolta ui'),
      'A role that could administer Scolta must still reach the settings screen it has always used'
    );
    $this->assertTrue(
      $role->hasPermission('administer scolta'),
      'The backend permission it already held is not taken away'
    );

    // And the screen actually serves for a user in that role.
    $user = $this->drupalCreateUser();
    $user->addRole($roleId);
    $user->save();
    $this->drupalLogin($user);

    $this->drupalGet('admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * The migrated values reach the browser, as they did before the split.
   */
  public function testMigratedConfigStillReachesTheBrowser(): void {
    $this->simulatePreSplitSite();
    $this->runSplitUpdate();

    // A remote origin so the block treats the index as present without a
    // build; what is under test is the scoring and display config it emits,
    // not whether this test site has an index.
    $this->config('scolta_ui.settings')->set('index_origin', 'https://index.example.com')->save();

    $this->drupalPlaceBlock('scolta_search');
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);

    $settings = $this->getDrupalSettings();
    $this->assertSame('Fixture Site', $settings['scolta']['siteName']);
    $this->assertSame('deferred', $settings['scolta']['facetMode']);
    $this->assertFalse($settings['scolta']['saytEnabled']);
    $this->assertSame(4, $settings['scolta']['saytMinChars']);
    $this->assertSame(
      ['topic' => 'Subject area'],
      $settings['scolta']['filterFieldDescriptions']
    );
  }

}
