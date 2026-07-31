<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\scolta\Form\ScoltaSettingsForm;
use Drupal\Tests\BrowserTestBase;

/**
 * Functional coverage for scolta_update_10003().
 *
 * Sites installed before scolta-drupal#187 can be carrying an Amazee LiteLLM
 * gateway alias (e.g. claude-4-5-sonnet) in scolta.settings:ai_model, because
 * model resolution used to write it there unconditionally. That name is only
 * valid against the Amazee gateway, so the moment the trial expires or the
 * operator configures a direct provider key, AI fails with a generic ai_error.
 *
 * The update hook moves such a value into the gateway-scoped amazee_model and
 * puts ai_model back to the shipped default — but only where the old callback
 * could actually have written, which is a site with stored Amazee credentials.
 * A site without them is left strictly alone: its ai_model may be an explicit
 * administrator choice, and resetting that would recreate the very bug being
 * fixed.
 *
 * @group scolta
 */
class AmazeeModelMigrationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  private const GATEWAY_ALIAS = 'claude-4-5-sonnet';
  private const GATEWAY_EXPANSION_ALIAS = 'claude-3-5-haiku';
  private const ADMIN_CHOICE = 'claude-opus-4-1-20250805';

  /**
   * A credentialed site's stranded alias moves to the gateway key.
   */
  public function testAliasMovesOutOfTheOperatorKeyOnACredentialedSite(): void {
    $this->storeAmazeeCredentials();
    $this->simulatePreFixSite(self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS);

    $message = (string) $this->runMigration();
    $config = $this->config('scolta.settings');

    $this->assertSame(self::GATEWAY_ALIAS, $config->get('amazee_model'));
    $this->assertSame(self::GATEWAY_EXPANSION_ALIAS, $config->get('amazee_expansion_model'));
    $this->assertSame(
      ScoltaSettingsForm::DEFAULT_AI_MODEL,
      $config->get('ai_model'),
      'The operator-facing key goes back to a provider-native default'
    );
    $this->assertSame('', $config->get('ai_expansion_model'));
    $this->assertStringContainsString(
      self::GATEWAY_ALIAS,
      $message,
      'The hook must name the alias it moved so an operator can find it'
    );
  }

  /**
   * Without Amazee credentials nothing is touched — the value may be a choice.
   */
  public function testSiteWithoutAmazeeCredentialsIsLeftAlone(): void {
    $this->clearAmazeeCredentials();
    $this->simulatePreFixSite(self::ADMIN_CHOICE, '');

    $this->runMigration();
    $config = $this->config('scolta.settings');

    $this->assertSame(
      self::ADMIN_CHOICE,
      $config->get('ai_model'),
      'An administrator model on an uncredentialed site must survive the update'
    );
    $this->assertSame('', $config->get('amazee_model'));
  }

  /**
   * A site already holding a gateway alias in the new key is not re-migrated.
   */
  public function testAlreadyMigratedSiteIsLeftAlone(): void {
    $this->storeAmazeeCredentials();
    $config = \Drupal::configFactory()->getEditable('scolta.settings');
    $config
      ->set('ai_model', self::ADMIN_CHOICE)
      ->set('amazee_model', self::GATEWAY_ALIAS)
      ->set('amazee_expansion_model', self::GATEWAY_EXPANSION_ALIAS)
      ->save();

    $this->runMigration();

    $this->assertSame(self::ADMIN_CHOICE, $this->config('scolta.settings')->get('ai_model'));
    $this->assertSame(self::GATEWAY_ALIAS, $this->config('scolta.settings')->get('amazee_model'));
  }

  /**
   * The shipped default is not an alias, so a credentialed site keeps it.
   */
  public function testShippedDefaultIsNotMigrated(): void {
    $this->storeAmazeeCredentials();
    $this->simulatePreFixSite(ScoltaSettingsForm::DEFAULT_AI_MODEL, '');

    $this->runMigration();
    $config = $this->config('scolta.settings');

    $this->assertSame(ScoltaSettingsForm::DEFAULT_AI_MODEL, $config->get('ai_model'));
    $this->assertSame('', $config->get('amazee_model'), 'Nothing was resolved, so nothing moves');
  }

  /**
   * Every site gets the two keys, migration or not.
   *
   * config/install only applies to a fresh install, so without a backfill the
   * settings form would be saving keys config had never held.
   */
  public function testGatewayKeysAreBackfilledOnEverySite(): void {
    $this->clearAmazeeCredentials();
    $this->simulatePreFixSite(ScoltaSettingsForm::DEFAULT_AI_MODEL, '');

    $this->runMigration();
    $config = $this->config('scolta.settings');

    $this->assertSame('', $config->get('amazee_model'));
    $this->assertSame('', $config->get('amazee_expansion_model'));
  }

  /**
   * Running the hook a second time changes nothing.
   */
  public function testSecondRunIsANoOp(): void {
    $this->storeAmazeeCredentials();
    $this->simulatePreFixSite(self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS);

    $this->runMigration();
    $after = $this->config('scolta.settings')->getRawData();

    $this->runMigration();

    $this->assertSame($after, $this->config('scolta.settings')->getRawData());
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  /**
   * Put the site on the Amazee.ai path with stored credentials.
   */
  private function storeAmazeeCredentials(): void {
    \Drupal::service('scolta.amazee_config_storage')
      ->store('sk-stored-token', 'https://llm.test.amazee.ai', 'test-region');
  }

  /**
   * Take the site off the Amazee.ai path.
   *
   * A fresh install stores nothing, so this is normally a no-op; it stays
   * because a test about the unconnected case should establish that state
   * rather than inherit it from whatever ran before.
   */
  private function clearAmazeeCredentials(): void {
    \Drupal::service('scolta.amazee_config_storage')->clear();
  }

  /**
   * Recreate a site installed before the gateway keys existed.
   */
  private function simulatePreFixSite(string $aiModel, string $aiExpansionModel): void {
    \Drupal::configFactory()->getEditable('scolta.settings')
      ->set('ai_model', $aiModel)
      ->set('ai_expansion_model', $aiExpansionModel)
      ->clear('amazee_model')
      ->clear('amazee_expansion_model')
      ->save();
  }

  /**
   * Loads scolta.install and runs the migration hook.
   *
   * @return mixed
   *   Whatever the hook returned.
   */
  private function runMigration() {
    \Drupal::moduleHandler()->loadInclude('scolta', 'install');
    $this->assertTrue(
      function_exists('scolta_update_10003'),
      'scolta.install must define scolta_update_10003()'
    );

    return scolta_update_10003();
  }

}
