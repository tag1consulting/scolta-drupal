<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Submitting the Amazee.ai settings form finishes on a rendered page.
 *
 * Before the form's collaborators were services, the rebuild after every
 * submit serialized a Guzzle client and died on "Serialization of 'Closure' is
 * not allowed". Disconnect makes no call to amazee.ai; the demo and the sign
 * in flow run against scolta_amazee_test's canned responses, so nothing here
 * reaches the network.
 *
 * @group scolta
 */
class AmazeeSettingsFormSubmitFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'scolta_amazee_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  private const AMAZEE_PATH = '/admin/config/search/scolta/amazee';
  private const SETTINGS_PATH = '/admin/config/search/scolta';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer scolta']));
  }

  /**
   * The demo connects, selects Amazee.ai, and lands on the main settings page.
   */
  public function testTryTheDemoRedirectsToSettings(): void {
    $this->drupalGet(self::AMAZEE_PATH);
    $this->submitForm([], 'Try the demo');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals(self::SETTINGS_PATH);
    $this->assertSession()->pageTextContains('Connected to Amazee.ai. The demo is active. AI model set to claude-sonnet-4-5.');
    $config = $this->config('scolta.settings');
    $this->assertSame('amazee', $config->get('ai_provider'));
    $this->assertSame('claude-sonnet-4-5', $config->get('amazee_model'));
    $this->assertSame('claude-haiku-4-5', $config->get('amazee_expansion_model'));
  }

  /**
   * Every step of the sign in flow renders, and Connect lands on settings.
   */
  public function testSignInFlowStepsRenderAndConnectRedirects(): void {
    $this->drupalGet(self::AMAZEE_PATH);
    $this->submitForm(['email' => 'operator@example.com'], 'Send verification code');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Verification code sent to operator@example.com.');

    // Back rebuilds the start step, then forward again.
    $this->submitForm([], 'Back');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Try the demo');
    $this->submitForm(['email' => 'operator@example.com'], 'Send verification code');

    $this->submitForm(['code' => '123456'], 'Verify code');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Fake region');

    $this->submitForm(['region' => 'fake-region'], 'Connect');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals(self::SETTINGS_PATH);
    $this->assertSession()->pageTextContains('Successfully connected to Amazee.ai.');
    $this->assertSame('amazee', $this->config('scolta.settings')->get('ai_provider'));
  }

  /**
   * Disconnect clears the connection and lands on the start step.
   */
  public function testDisconnectLandsOnStartStep(): void {
    \Drupal::service('scolta.amazee_config_storage')
      ->store('sk-stored-token', 'https://llm.test.amazee.ai', 'test-region');

    $this->drupalGet(self::AMAZEE_PATH);
    $this->submitForm([], 'Disconnect');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals(self::AMAZEE_PATH);
    $this->assertSession()->pageTextContains('Disconnected from Amazee.ai.');
    $this->assertSession()->buttonExists('Try the demo');
    $this->assertSession()->buttonNotExists('Disconnect');
    $this->assertNull(\Drupal::service('scolta.amazee_config_storage')->load());
  }

}
