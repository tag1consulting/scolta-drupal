<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\block\Entity\Block;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\UpdatePathTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Runs scolta_update_10010() against a pre-split site and checks the result.
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
 * Most tests call scolta_update_10010() directly. The ones that check the
 * hooks before it applying the split first instead set scolta's schema back
 * and run update.php, so the hooks run in the order a real upgrade runs them.
 *
 * The fixture is built by writing the pre-split shape back into
 * scolta.settings — a single object holding both halves — which is exactly
 * the state a site updating into this release is in.
 *
 * @group scolta
 */
class ConfigSplitUpdateFunctionalTest extends BrowserTestBase {

  use UpdatePathTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'block'];

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
  protected $strictConfigSchema = FALSE; // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema

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
    'auto_language_filter' => TRUE,
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
    'body_fields' => ['field_body', 'field_summary'],
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
   * Runs the split update the way `drush updatedb` would.
   */
  private function runSplitUpdate() {
    return $this->runUpdate('scolta_update_10010');
  }

  /**
   * Runs one of scolta's update hooks the way `drush updatedb` would.
   */
  private function runUpdate(string $hook) {
    \Drupal::moduleHandler()->loadInclude('scolta', 'install');
    $this->assertTrue(function_exists($hook), "scolta.install must define {$hook}()");

    $message = $hook();
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
   * An upgrade from before 10001 applies the split in 10001, then carries on.
   *
   * 10001 grants a permission only scolta_ui defines, and 10002, 10003, 10004
   * and 10009 write keys only scolta_ui.settings declares, so on this site the
   * split has to happen at the very start of the run. It is also the case
   * that put auto_language_filter's typing in the split: 10009 only runs once
   * the split has already cast the stored string.
   */
  public function testUpgradeFromBefore10001AppliesTheSplitFirst(): void {
    $this->simulatePreSplitSite();
    // As a site that set the key before #332 declared it may have stored it.
    \Drupal::configFactory()->getEditable('scolta.settings')->set('auto_language_filter', 'false')->save();
    \Drupal::service('update.update_hook_registry')->setInstalledVersion('scolta', 10000);

    $this->runUpdates();

    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('scolta_ui'));
    $this->assertSame(10010, \Drupal::service('update.update_hook_registry')->getInstalledVersion('scolta'));
    $this->assertSame('Fixture Site', $this->config('scolta_ui.settings')->get('site_name'));
    $this->assertNull($this->config('scolta.settings')->get('site_name'));
    $this->assertFalse(
      $this->config('scolta_ui.settings')->get('auto_language_filter'),
      'The string "false" must arrive in scolta_ui.settings as a real FALSE'
    );
    // 10001 grants the AI permission to both roles, which needed the split
    // to have happened; 10004 then takes it back from anonymous on purpose.
    $this->assertTrue(
      $this->loadRole(RoleInterface::AUTHENTICATED_ID)->hasPermission('use scolta ai'),
      "10001 must grant 'use scolta ai' to the authenticated role"
    );
    $this->assertFalse(
      $this->loadRole(RoleInterface::ANONYMOUS_ID)->hasPermission('use scolta ai'),
      "10004 revokes 'use scolta ai' from anonymous, after 10001 granted it"
    );
  }

  /**
   * A site that revoked the AI grant from logged-in users keeps it revoked.
   *
   * Enabling scolta_ui runs scolta_ui_install(), which grants 'use scolta ai'
   * to the authenticated role, as is right for a fresh install. This site
   * already decided otherwise, and the AI endpoints cost money per request.
   */
  public function testUpgradeKeepsEachRolesAiGrantAsItWas(): void {
    $this->simulatePreSplitSite();
    // The roles as a pre-split site stores them: a site role holds the grant,
    // and authenticated had it revoked. Written as raw config, because
    // nothing on this codebase defines the permission until scolta_ui is
    // enabled. Not anonymous: 10004 revokes its grant on purpose.
    $roleId = $this->drupalCreateRole([], 'researcher');
    $role = \Drupal::configFactory()->getEditable("user.role.{$roleId}");
    $role->set('permissions', ['use scolta ai'])->save();
    $this->assertFalse($this->loadRole(RoleInterface::AUTHENTICATED_ID)->hasPermission('use scolta ai'));
    // Past 10001, so nothing in the run grants the permission on purpose.
    \Drupal::service('update.update_hook_registry')->setInstalledVersion('scolta', 10001);

    $this->runUpdates();

    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('scolta_ui'));
    $this->assertTrue(
      $this->loadRole($roleId)->hasPermission('use scolta ai'),
      'A role that held the AI grant before the split must still hold it'
    );
    $this->assertFalse(
      $this->loadRole(RoleInterface::AUTHENTICATED_ID)->hasPermission('use scolta ai'),
      'Enabling scolta_ui must not hand logged-in users an AI grant this site revoked'
    );
  }

  /**
   * A search block placed before the split survives uninstalling scolta.
   *
   * A block depends on the module named in its settings.provider. Left
   * naming scolta, uninstalling scolta on a frontend-only site -- the case
   * the split exists for -- deletes the site's search block with it.
   */
  public function testPlacedSearchBlockSurvivesUninstallingScolta(): void {
    // Place the block while its plugin exists, then rewrite it to what a
    // pre-split site stores: provided by scolta, and depending on it.
    \Drupal::service('module_installer')->install(['scolta_ui']);
    $this->drupalPlaceBlock('scolta_search', ['id' => 'site_search']);
    $raw = \Drupal::configFactory()->getEditable('block.block.site_search');
    $raw->set('settings.provider', 'scolta')
      ->set('dependencies.module', ['scolta'])
      ->save();
    $this->simulatePreSplitSite();
    $this->assertNotNull($this->loadBlock('site_search'), 'Fixture: uninstalling scolta_ui must not take the pre-split block');

    $this->runSplitUpdate();

    $block = $this->config('block.block.site_search');
    $this->assertSame('scolta_ui', $block->get('settings.provider'));
    $this->assertContains('scolta_ui', $block->get('dependencies.module'));
    $this->assertNotContains('scolta', $block->get('dependencies.module'));

    \Drupal::service('module_installer')->uninstall(['scolta']);

    $this->assertNotNull(
      $this->loadBlock('site_search'),
      'Uninstalling scolta must not delete a search block scolta_ui now provides'
    );
  }

  /**
   * Loads a role past the entity cache, which config writes here bypass.
   */
  private function loadRole(string $roleId): RoleInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('user_role');
    $storage->resetCache([$roleId]);
    return $storage->load($roleId);
  }

  /**
   * Loads a block past the entity cache.
   */
  private function loadBlock(string $id): ?Block {
    $storage = \Drupal::entityTypeManager()->getStorage('block');
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

  /**
   * The auto_language_filter update writes where the setting lives now.
   *
   * On a site that set the key before it was declared, the value sits in
   * scolta.settings, possibly as the string the old config could not type.
   * 10009 runs before 10010, so it has to move it across, then type it.
   */
  public function testAutoLanguageFilterUpdateAppliesTheSplitFirst(): void {
    $this->simulatePreSplitSite();
    \Drupal::configFactory()->getEditable('scolta.settings')->set('auto_language_filter', 'false')->save();

    $this->runUpdate('scolta_update_10009');

    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('scolta_ui'));
    $this->assertNull($this->config('scolta.settings')->get('auto_language_filter'));
    $this->assertFalse(
      $this->config('scolta_ui.settings')->get('auto_language_filter'),
      'The string "false" must arrive in scolta_ui.settings as a real FALSE'
    );
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
