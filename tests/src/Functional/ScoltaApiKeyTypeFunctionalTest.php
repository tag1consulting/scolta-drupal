<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Core\Site\Settings;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests that a non-string $settings['scolta.api_key'] cannot fatal the site.
 *
 * The common way to keep the key out of exported config is
 * $settings['scolta.api_key'] = getenv('SCOLTA_API_KEY'); which stores boolean
 * FALSE in every environment that does not define the variable. Settings::get()
 * hands that back untouched, and getApiKey() declares a string return type, so
 * pre-fix the getter threw a TypeError — from a service constructed on nearly
 * every code path, which took down every Drush command in that environment.
 *
 * These have to be functional tests: the unit suite in tests/src/ never boots
 * Drupal and asserts on the source text, so it cannot execute the getter with a
 * real Settings singleton behind it.
 *
 * @group scolta
 */
class ScoltaApiKeyTypeFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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
    // getApiKey() returns at the environment branch and never reaches the
    // settings.php fallback while SCOLTA_API_KEY is defined, which is why a
    // DDEV shell can never reproduce this. Unset it for the duration.
    $this->originalEnvKey = getenv('SCOLTA_API_KEY');
    putenv('SCOLTA_API_KEY');
    // Amazee credentials short-circuit getApiKeySource() before it reads
    // settings.php, so clear them to keep the source assertions deterministic.
    $this->container->get('state')->delete('scolta.amazee.credentials');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->originalEnvKey !== FALSE) {
      putenv('SCOLTA_API_KEY=' . $this->originalEnvKey);
    }
    parent::tearDown();
  }

  /**
   * Writes a raw $settings value into the test site's settings.php.
   *
   * @param mixed $value
   *   The value to store under $settings['scolta.api_key'].
   */
  protected function writeApiKeySetting($value): void {
    $this->writeSettings([
      'settings' => [
        'scolta.api_key' => (object) [
          'value' => $value,
          'required' => TRUE,
        ],
      ],
    ]);
    $this->rebuildAll();
  }

  /**
   * A FALSE key degrades to unconfigured instead of throwing a TypeError.
   *
   * Also covers that the two readers agree: pre-fix, getApiKey() threw a
   * TypeError on this value (PHPUnit reports that as an error) while
   * getApiKeySource() had no equivalent guard, so the two could disagree
   * about whether a key was configured at all.
   */
  public function testFalseApiKeySettingReturnsEmptyString(): void {
    $this->writeApiKeySetting(FALSE);

    // Guard the fixture itself: if this is not FALSE the test proves nothing.
    $this->assertFalse(Settings::get('scolta.api_key'),
      'The test fixture must actually store boolean FALSE in settings.php');

    $service = $this->container->get('scolta.ai_service');
    $this->assertSame('', $service->getApiKey(),
      'getApiKey() must return an empty string for a FALSE settings.php value');
    $this->assertSame('none', $service->getApiKeySource(),
      'A FALSE settings.php value must not count as a configured key source');
  }

  /**
   * Other wrong types degrade the same way; none of them are fatal.
   */
  public function testNonStringApiKeySettingsAreIgnored(): void {
    foreach ([NULL, 0, 12345, ['sk-not-a-string']] as $value) {
      $this->writeApiKeySetting($value);

      $service = $this->container->get('scolta.ai_service');
      $this->assertSame('', $service->getApiKey(),
        sprintf('getApiKey() must return an empty string for a %s value', gettype($value)));
      $this->assertSame('none', $service->getApiKeySource(),
        sprintf('getApiKeySource() must report "none" for a %s value', gettype($value)));
    }
  }

  /**
   * The guard must not swallow a correctly configured string key.
   */
  public function testStringApiKeySettingStillReachesTheService(): void {
    $this->writeApiKeySetting('sk-settings-php-key');

    $service = $this->container->get('scolta.ai_service');
    $this->assertSame('sk-settings-php-key', $service->getApiKey(),
      'A string settings.php key must still be returned unchanged');
    $this->assertSame('settings', $service->getApiKeySource(),
      'A string settings.php key must still be reported as the "settings" source');
  }

}
