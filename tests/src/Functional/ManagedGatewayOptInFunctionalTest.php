<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\scolta\Cache\DrupalCacheDriver;
use Drupal\Tests\BrowserTestBase;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;

/**
 * The AI settings screen's behavior around the managed Amazee.ai gateway.
 *
 * The config/state-only behavior (which provider gets a stored connection,
 * the opt-in update hook) is covered by ManagedGatewayOptInKernelTest, which
 * needs no HTTP request. These four cases are specifically about what the
 * settings form does and renders, so they need BrowserTestBase.
 *
 * @group scolta
 */
class ManagedGatewayOptInFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  private const STATE_KEY = 'scolta.amazee.credentials';
  private const TOKEN = 'sk-stored-token';
  private const GATEWAY_URL = 'https://llm.test.amazee.ai';
  private const GATEWAY_ALIAS = 'claude-4-5-sonnet';

  /**
   * Admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * The SCOLTA_API_KEY value to restore after the test, or FALSE if unset.
   *
   * @var string|false
   */
  protected $originalEnvKey = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser(['administer scolta']);
    // The explicit-key paths outrank everything and are covered by the source
    // matrix; these cases are about the gateway, so make sure none is set.
    $this->originalEnvKey = getenv('SCOLTA_API_KEY');
    putenv('SCOLTA_API_KEY');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('SCOLTA_API_KEY' . ($this->originalEnvKey === FALSE ? '' : '=' . $this->originalEnvKey));
    parent::tearDown();
  }

  // ---------------------------------------------------------------------------
  // Switching away.
  // ---------------------------------------------------------------------------

  /**
   * Saving a different provider removes the connection and its markers.
   *
   * Left in place, a stored connection kept every status surface claiming the
   * site was on the managed gateway, and an expired one kept prompting for a
   * reconnect the operator had no use for and no way to clear from the UI.
   */
  public function testSwitchingProviderClearsTheStoredConnection(): void {
    $this->storeConnection();
    $this->selectProvider('amazee');
    $this->recovery()->flagUpgradeNeeded();
    $this->recovery()->recordAuthFailure();

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->submitForm(['ai_provider' => 'anthropic'], 'Save configuration');

    $this->assertSame(
      'anthropic',
      $this->config('scolta.settings')->get('ai_provider'),
      'The selected provider must be saved'
    );
    $this->assertNull(
      \Drupal::state()->get(self::STATE_KEY),
      'The stored managed-gateway connection must be removed'
    );
    $this->assertFalse(
      $this->recovery()->isUpgradeNeeded(),
      'The reconnect marker must not outlive the connection it describes'
    );
    $this->assertFalse(
      $this->recovery()->isAuthFailing(),
      'The auth-failure marker must not keep health reporting a removed connection as degraded'
    );
  }

  /**
   * Saving the form with the gateway still selected leaves it connected.
   */
  public function testSavingWithTheGatewayStillSelectedKeepsTheConnection(): void {
    $this->storeConnection();
    $this->selectProvider('amazee');

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->submitForm(['ai_provider' => 'amazee'], 'Save configuration');

    $this->assertNotNull(
      \Drupal::state()->get(self::STATE_KEY),
      'Saving unrelated settings must not disconnect the selected gateway'
    );
  }

  // ---------------------------------------------------------------------------
  // The settings screen.
  // ---------------------------------------------------------------------------

  /**
   * With the gateway selected and nothing stored, the screen says what is left.
   */
  public function testSettingsScreenAsksForTheConnectStepWhenNothingIsStored(): void {
    $this->selectProvider('amazee');
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    $this->assertSession()->pageTextContains('No Amazee.ai connection yet');
    $link = $this->getSession()->getPage()->findLink('Set up Amazee.ai');
    $this->assertNotNull($link, 'The call to action must render');
    $this->assertStringContainsString(
      '/admin/config/search/scolta/amazee',
      (string) $link->getAttribute('href'),
      'The call to action must route to the Amazee.ai settings flow'
    );
  }

  /**
   * Once a connection is stored, the screen stops asking for it.
   */
  public function testSettingsScreenDropsTheCallToActionOnceConnected(): void {
    $this->storeConnection();
    $this->selectProvider('amazee');
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');

    $this->assertSession()->pageTextNotContains('No Amazee.ai connection yet');
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * A recovery over the same store and cache bin the service records into.
   */
  private function recovery(): KeyExpiryRecovery {
    return new KeyExpiryRecovery(
      \Drupal::service('scolta.amazee_config_storage'),
      new DrupalCacheDriver(\Drupal::cache()),
    );
  }

  /**
   * Store a managed-gateway connection with a resolved gateway model.
   *
   * Written to state directly rather than through the storage service: the
   * resolution path reads the raw state value, so a test that stores an
   * encrypted token would be asserting against a key no surface reports.
   */
  private function storeConnection(): void {
    \Drupal::state()->set(self::STATE_KEY, [
      'litellm_token' => self::TOKEN,
      'litellm_api_url' => self::GATEWAY_URL,
      'region' => 'test-region',
    ]);
    \Drupal::configFactory()->getEditable('scolta.settings')
      ->set('amazee_model', self::GATEWAY_ALIAS)
      ->save();
  }

  /**
   * Save an AI provider selection.
   */
  private function selectProvider(string $provider): void {
    \Drupal::configFactory()->getEditable('scolta.settings')
      ->set('ai_provider', $provider)
      ->save();
  }

}
