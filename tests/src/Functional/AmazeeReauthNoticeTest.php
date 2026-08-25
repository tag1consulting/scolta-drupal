<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\scolta_ui\Cache\DrupalCacheDriver;
use Drupal\Tests\BrowserTestBase;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;

/**
 * Functional coverage for the Amazee.ai re-authentication admin notice.
 *
 * When the stored Amazee.ai credentials are no longer accepted, hook_page_top
 * surfaces a warning with a call to action that routes the operator to the
 * Amazee.ai settings flow to reconnect/upgrade. The prompt is gated on the
 * persistent marker ScoltaAiService exposes, shows only to admins on admin
 * routes, and disappears once the marker is cleared (a successful reconnect).
 *
 * @group scolta
 */
class AmazeeReauthNoticeTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'scolta_ui'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  private const NOTICE_TEXT = 'needs to be re-authenticated';
  private const CTA_TEXT = 'Continue with Amazee.ai';
  private const AMAZEE_PATH = '/admin/config/search/scolta/amazee';

  /**
   * Admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The re-authentication notice is rendered by scolta_ui_page_top(),
    // which gates on 'administer scolta ui'.
    $this->adminUser = $this->drupalCreateUser(['administer scolta', 'administer scolta ui']);

    // Put the site on the Amazee.ai path: the provider selected and a stored
    // connection, with no explicit key, so ScoltaAiService wires the recovery
    // whose marker drives the notice.
    \Drupal::service('scolta.amazee_config_storage')
      ->store('sk-stored-token', 'https://llm.test.amazee.ai', 'test-region');
    $this->config('scolta_ui.settings')->set('ai_provider', 'amazee')->save();
  }

  /**
   * Build a recovery over the same store + default cache the service uses.
   */
  private function recovery(): KeyExpiryRecovery {
    return new KeyExpiryRecovery(
      \Drupal::service('scolta.amazee_config_storage'),
      new DrupalCacheDriver(\Drupal::cache()),
    );
  }

  /**
   * When the marker is set, the notice and its Amazee.ai CTA render for admins.
   */
  public function testNoticeRendersWithCtaWhenReauthNeeded(): void {
    $this->recovery()->flagUpgradeNeeded();
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextContains(self::NOTICE_TEXT);

    $link = $this->getSession()->getPage()->findLink(self::CTA_TEXT);
    $this->assertNotNull($link, 'The re-authentication CTA must render');
    $this->assertStringContainsString(
      self::AMAZEE_PATH,
      (string) $link->getAttribute('href'),
      'The CTA must route to the Amazee.ai settings flow'
    );
  }

  /**
   * Without the marker, no notice renders — the prompt only shows when needed.
   */
  public function testNoNoticeWhenReauthNotNeeded(): void {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextNotContains(self::NOTICE_TEXT);
  }

  /**
   * Clearing the marker (a successful reconnect) removes the notice.
   */
  public function testNoticeClearsAfterReauthMarkerCleared(): void {
    $this->recovery()->flagUpgradeNeeded();
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextContains(self::NOTICE_TEXT);

    // A successful reconnect clears the marker (see AmazeeSettingsForm).
    $this->recovery()->clearUpgradeNeeded();

    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextNotContains(self::NOTICE_TEXT);
  }

  /**
   * A site on its own provider never sees the reconnect prompt.
   *
   * The marker describes a managed-gateway connection. Once the operator has
   * selected a different provider that connection governs nothing, so telling
   * them AI is down until they reconnect it points at the wrong thing on
   * every admin screen — and the banner had no dismiss of its own.
   */
  public function testNoNoticeWhenAnotherProviderIsSelected(): void {
    $this->recovery()->flagUpgradeNeeded();
    $this->config('scolta_ui.settings')->set('ai_provider', 'anthropic')->save();
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextNotContains(self::NOTICE_TEXT);
  }

  /**
   * Non-admin users never see the prompt, even when the marker is set.
   */
  public function testNoticeHiddenFromNonAdmins(): void {
    $this->recovery()->flagUpgradeNeeded();
    $viewer = $this->drupalCreateUser([]);
    $this->drupalLogin($viewer);

    $this->drupalGet('/user');
    $this->assertSession()->pageTextNotContains(self::NOTICE_TEXT);
  }

}
