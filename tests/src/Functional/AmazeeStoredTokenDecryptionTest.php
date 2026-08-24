<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\scolta_ui\Service\ScoltaAiService;
use Drupal\Tests\BrowserTestBase;

/**
 * The token that reaches the gateway is the one the operator connected with.
 *
 * DrupalConfigStorage encrypts the LiteLLM token at rest and decrypts it in
 * load(). The request path never called load(): resolveApiKey() read the raw
 * State array, so ai_api_key carried the ciphertext and the gateway rejected
 * the bearer token on every message, expand and summarize call. Model
 * resolution kept working because the self-heal in createClient() already went
 * through load(), which is what made an entirely broken connection look like a
 * selective failure.
 *
 * Functional rather than unit: the encryption key is derived from
 * Settings::getHashSalt() and the ciphertext lives in State, so proving the
 * round trip needs a real Drupal. The unit job declares
 * `provide: {drupal/core: ...}`, where neither service exists, and a unit test
 * that stubbed the store would assert nothing about decryption.
 *
 * @group scolta
 */
class AmazeeStoredTokenDecryptionTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The plaintext token, as an operator's connection would supply it.
   */
  private const TOKEN = 'sk-litellm-plaintext-token';

  private const GATEWAY_URL = 'https://llm.test.amazee.ai';

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
    $this->originalEnvKey = getenv('SCOLTA_API_KEY');
    putenv('SCOLTA_API_KEY');
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
   * The fixture really is encrypted, so what follows is a decryption test.
   */
  public function testTheStoredTokenIsCiphertextAtRest(): void {
    $this->connectManagedGateway();

    $stored = \Drupal::state()->get('scolta.amazee.credentials');

    $this->assertIsArray($stored);
    $this->assertNotSame(
      self::TOKEN,
      $stored['litellm_token'],
      'store() must encrypt the token, or this test proves nothing'
    );
    $this->assertSame(
      self::TOKEN,
      \Drupal::service('scolta.amazee_config_storage')->load()['litellm_token'],
      'load() must give the plaintext back'
    );
  }

  /**
   * The resolved key is the plaintext token, not what is stored.
   */
  public function testResolvedKeyIsTheDecryptedToken(): void {
    $this->connectManagedGateway();

    $resolved = $this->service()->resolveApiKey();

    $this->assertSame(self::TOKEN, $resolved->key, 'The resolver must hand back the decrypted token');
    $this->assertSame('amazee', $resolved->source->value);
    $this->assertSame(self::GATEWAY_URL, $resolved->baseUrl);
  }

  /**
   * The key the AI client is built with is the plaintext token.
   *
   * ai_api_key becomes the bearer token on every message, expand and summarize
   * call, so ciphertext here is an authentication failure against the gateway.
   */
  public function testBuiltConfigCarriesTheDecryptedToken(): void {
    $this->connectManagedGateway();
    $service = $this->service();

    // ReflectionMethod ignores visibility since PHP 8.1 (the package floor).
    $built = (new \ReflectionMethod(ScoltaAiService::class, 'buildConfig'))->invoke($service);

    $this->assertSame(self::TOKEN, $built->aiApiKey, 'buildConfig() must set ai_api_key to the plaintext token');
    $this->assertSame(
      self::TOKEN,
      $built->toAiClientConfig()['api_key'],
      'The client is built from this array, so it is what authenticates the request'
    );
    $this->assertSame(self::TOKEN, $service->getConfig()->aiApiKey);
  }

  /**
   * An explicit key still wins over stored credentials, and still says so.
   *
   * Decrypting the stored token changes where it is read, nothing about the
   * precedence: a site running on its own key must not be rerouted through the
   * managed gateway or told that it was.
   */
  public function testAnExplicitKeyStillWinsOverTheStoredToken(): void {
    $this->connectManagedGateway();
    putenv('SCOLTA_API_KEY=sk-explicit-operator-key');

    $resolved = $this->service()->resolveApiKey();

    $this->assertSame('sk-explicit-operator-key', $resolved->key);
    $this->assertSame('env', $resolved->source->value);
    $this->assertTrue($resolved->amazeeCredentialsStored, 'Stored credentials stay visible, they just lost');
  }

  // ---------------------------------------------------------------------------
  // Helpers.
  // ---------------------------------------------------------------------------

  /**
   * Put the site on the managed gateway the way a real connection does.
   *
   * Through store(), so the token is encrypted at rest exactly as it is on a
   * provisioned site, with model resolution already complete.
   */
  private function connectManagedGateway(): void {
    \Drupal::service('scolta.amazee_config_storage')
      ->store(self::TOKEN, self::GATEWAY_URL, 'test-region');

    \Drupal::configFactory()->getEditable('scolta.settings')
      ->set('ai_provider', 'amazee')
      ->set('amazee_model', 'claude-4-5-sonnet')
      ->set('amazee_expansion_model', 'claude-3-5-haiku')
      ->save();
  }

  /**
   * Build a service over the config and state as they stand now.
   *
   * Constructed rather than pulled from the container: buildConfig() runs once,
   * in the constructor, so a container instance created before this test stored
   * anything would answer from the state it booted with.
   */
  private function service(): ScoltaAiService {
    return new ScoltaAiService(
      \Drupal::httpClient(),
      \Drupal::configFactory(),
      \Drupal::logger('scolta'),
      NULL,
      \Drupal::service('scolta.amazee_config_storage'),
    );
  }

}
