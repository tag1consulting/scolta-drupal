<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Service\ScoltaAiService;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Tag1\Scolta\Exception\ApiKeyMissingException;

/**
 * The managed Amazee.ai gateway is used only when it is the selected provider.
 *
 * Enabling it is two deliberate acts: selecting it on the AI settings screen
 * and completing the connect flow. Nothing else enables it — not installing
 * the module, not serving a request — and selecting a different provider
 * removes the stored connection so it cannot shadow the operator's own key.
 *
 * Kernel rather than unit: the unit job declares `provide: {drupal/core:
 * ...}`, so no config factory, state service or role entity exists there to
 * assert against. No HTTP request is involved in these cases; the settings
 * screen and the form-save path are covered separately in
 * ManagedGatewayOptInFunctionalTest, which needs BrowserTestBase.
 *
 * @group scolta
 */
class ManagedGatewayOptInKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta', 'search_api'];

  private const STATE_KEY = 'scolta.amazee.credentials';
  private const TOKEN = 'sk-stored-token';
  private const GATEWAY_URL = 'https://llm.test.amazee.ai';
  private const GATEWAY_ALIAS = 'claude-4-5-sonnet';

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
    $this->installConfig(['scolta', 'user']);
    // Kernel tests enable a module via $modules without installing it, so
    // hook_install() (scolta_install()) never runs — including the grant of
    // 'use scolta ai' to the authenticated role that a real install performs.
    // Reproduce just that grant, matching the fresh-install baseline these
    // tests assume.
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['use scolta ai']);
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
  // Install.
  // ---------------------------------------------------------------------------

  /**
   * Installing the module stores no connection and selects no gateway.
   */
  public function testInstallDoesNotEnableTheManagedGateway(): void {
    $this->assertNull(
      \Drupal::state()->get(self::STATE_KEY),
      'A fresh install must store no managed-gateway credentials'
    );
    $this->assertSame(
      '',
      $this->config('scolta.settings')->get('ai_provider'),
      'A fresh install must select no AI provider at all: AI is off until an '
      . 'operator chooses one, and in particular is not Anthropic'
    );
    $this->assertSame(
      'none',
      $this->service()->getApiKeySource(),
      'A fresh install must report no API key of any kind'
    );
  }

  /**
   * Installing the module does not open the AI endpoints to anonymous traffic.
   */
  public function testInstallDoesNotGrantAnonymousAiAccess(): void {
    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    $this->assertNotNull($anonymous, 'The anonymous role must exist');
    $this->assertFalse(
      $anonymous->hasPermission('use scolta ai'),
      "A fresh install must not grant 'use scolta ai' to the anonymous role"
    );

    $authenticated = Role::load(RoleInterface::AUTHENTICATED_ID);
    $this->assertNotNull($authenticated, 'The authenticated role must exist');
    $this->assertTrue(
      $authenticated->hasPermission('use scolta ai'),
      "Logged-in AI search is intended, so the authenticated grant stays"
    );
  }

  /**
   * Building a client on an unconfigured site reaches no gateway.
   *
   * The request path used to enable a connection whenever the key source was
   * 'none', so an ordinary page load could configure a gateway nobody chose.
   * Nothing stored now means nothing established and no outbound call.
   *
   * Driven through getClient(), the single entry point every AI call uses,
   * rather than the createClient() factory beneath it. With no provider
   * selected the guard there refuses to build a client at all — picking a
   * vendor on the site's behalf is what the no-default rule forbids — and
   * raises the ApiKeyMissingException the endpoint handlers already degrade to
   * an unexpanded, unsummarized HTTP 200. Reaching past that guard to the
   * factory would test a path no request can take.
   */
  public function testBuildingClientStoresNothingWhenNothingIsStored(): void {
    $service = $this->service();

    try {
      $this->buildClient($service);
      $this->fail('A client must not be built while no AI provider is selected');
    }
    catch (ApiKeyMissingException $e) {
      // The degradation itself: AI is off, and the endpoints turn this into a
      // plain unexpanded response rather than an error.
    }

    $this->assertNull(
      \Drupal::state()->get(self::STATE_KEY),
      'Building a client must not establish a managed-gateway connection'
    );
    $this->assertSame(
      '',
      $service->getConfig()->aiApiKey,
      'With nothing configured there is no key, so AI degrades rather than calling out'
    );
  }

  // ---------------------------------------------------------------------------
  // Which provider gets the stored connection.
  // ---------------------------------------------------------------------------

  /**
   * A stored connection is injected only for the provider that selects it.
   */
  public function testStoredConnectionIsUsedOnlyWhenTheGatewayIsSelected(): void {
    $this->storeConnection();
    $this->selectProvider('amazee');

    $config = $this->service()->getConfig();
    $this->assertSame('openai', $config->aiProvider, 'The gateway is OpenAI-compatible');
    $this->assertSame(self::GATEWAY_URL, $config->aiBaseUrl);
    $this->assertSame(self::TOKEN, $config->aiApiKey);
    $this->assertSame(self::GATEWAY_ALIAS, $config->aiModel);
  }

  /**
   * With another provider selected, the stored connection stays out of traffic.
   */
  public function testStoredConnectionIsIgnoredForAnotherProvider(): void {
    $this->storeConnection();
    $this->selectProvider('anthropic');

    $config = $this->service()->getConfig();
    $this->assertSame('anthropic', $config->aiProvider, 'The selected provider must survive');
    $this->assertSame('', $config->aiApiKey, 'The stored gateway token must not be sent');
    $this->assertNotSame(self::GATEWAY_URL, $config->aiBaseUrl, 'The gateway URL must not be sent');
    $this->assertNotSame(self::GATEWAY_ALIAS, $config->aiModel, 'The gateway alias must not be sent');
  }

  /**
   * The reported key source tells the truth about which key would be sent.
   */
  public function testKeySourceReportsTheGatewayOnlyWhenItIsSelected(): void {
    $this->storeConnection();

    $this->selectProvider('anthropic');
    $this->assertSame(
      'none',
      $this->service()->getApiKeySource(),
      'A stored connection the selected provider cannot use is not a configured key'
    );
    $this->assertFalse($this->service()->isAmazeeActive());

    $this->selectProvider('amazee');
    $this->assertSame(
      'amazee',
      $this->service()->getApiKeySource(),
      'Selected plus stored is what makes the managed gateway the source'
    );
    $this->assertTrue($this->service()->isAmazeeActive());
  }

  // ---------------------------------------------------------------------------
  // The update hook.
  // ---------------------------------------------------------------------------

  /**
   * A legacy connected site keeps the gateway it was already using.
   */
  public function testUpdateSelectsTheGatewayForLegacyConnectedSite(): void {
    $this->storeConnection();
    $this->selectProvider('anthropic');

    $this->runUpdate();

    $this->assertSame(
      'amazee',
      $this->config('scolta.settings')->get('ai_provider'),
      'A site whose traffic already went through the stored connection must keep working'
    );
  }

  /**
   * A site with an explicit key keeps the provider it chose.
   */
  public function testUpdateLeavesProviderAloneWhenExplicitKeyIsConfigured(): void {
    $this->storeConnection();
    $this->selectProvider('anthropic');
    // The update hook runs in this process, so an environment variable set
    // here is the one it reads — no site rebuild needed to make it explicit.
    putenv('SCOLTA_API_KEY=sk-operator-key');

    $this->runUpdate();

    $this->assertSame(
      'anthropic',
      $this->config('scolta.settings')->get('ai_provider'),
      'The stored connection never served this site, so its provider must not be touched'
    );
  }

  /**
   * A site with nothing stored keeps the provider it chose.
   */
  public function testUpdateLeavesProviderAloneWithNoStoredConnection(): void {
    $this->selectProvider('openai');

    $this->runUpdate();

    $this->assertSame(
      'openai',
      $this->config('scolta.settings')->get('ai_provider'),
      'There is no connection to carry over, so nothing changes'
    );
  }

  /**
   * The update closes the AI endpoints to anonymous traffic.
   */
  public function testUpdateRevokesTheAnonymousGrant(): void {
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['use scolta ai']);
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);

    $this->runUpdate();

    $anonymous = $this->reloadRole(RoleInterface::ANONYMOUS_ID);
    $this->assertFalse(
      $anonymous->hasPermission('use scolta ai'),
      "The update must revoke 'use scolta ai' from the anonymous role"
    );
    $this->assertTrue(
      $anonymous->hasPermission('access content'),
      'The update must not disturb permissions it did not grant'
    );
    $this->assertTrue(
      $this->reloadRole(RoleInterface::AUTHENTICATED_ID)->hasPermission('use scolta ai'),
      'The authenticated grant is intended and must survive'
    );
  }

  /**
   * Running the update twice changes nothing the second time.
   */
  public function testUpdateIsIdempotent(): void {
    $this->storeConnection();
    $this->selectProvider('anthropic');

    $this->runUpdate();
    $afterFirst = $this->config('scolta.settings')->get('ai_provider');
    $this->runUpdate();

    $this->assertSame($afterFirst, $this->config('scolta.settings')->get('ai_provider'));
    $this->assertFalse(
      $this->reloadRole(RoleInterface::ANONYMOUS_ID)->hasPermission('use scolta ai')
    );
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Build a service that reads the config and state as they stand now.
   *
   * Constructed rather than pulled from the container: buildConfig() runs
   * once, in the constructor, so a container instance created before a test
   * changed anything would answer from the state it booted with.
   */
  private function service(): ScoltaAiService {
    return new ScoltaAiService(
      \Drupal::httpClient(),
      \Drupal::configFactory(),
      \Drupal::logger('scolta'),
      NULL,
      \Drupal::service('scolta.amazee_config_storage'),
      NULL,
      \Drupal::cache(),
    );
  }

  /**
   * Invoke the protected createClient() and hand back the client.
   */
  private function buildClient(ScoltaAiService $service): object {
    // getClient(), not createClient(): the guard that refuses to build a client
    // with no provider selected lives there, and it is what every real AI call
    // goes through. ReflectionMethod ignores visibility since PHP 8.1 (the
    // package floor).
    $method = new \ReflectionMethod(ScoltaAiService::class, 'getClient');

    return $method->invoke($service);
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

  /**
   * Loads scolta.install and runs the opt-in update hook.
   */
  private function runUpdate(): void {
    \Drupal::moduleHandler()->loadInclude('scolta', 'install');
    $this->assertTrue(
      function_exists('scolta_update_10004'),
      'scolta.install must define scolta_update_10004()'
    );
    scolta_update_10004();
  }

  /**
   * Reload a role past the entity static cache.
   */
  private function reloadRole(string $role_id): Role {
    \Drupal::entityTypeManager()->getStorage('user_role')->resetCache([$role_id]);
    $role = Role::load($role_id);
    $this->assertNotNull($role, "Role {$role_id} must exist");

    return $role;
  }

}
