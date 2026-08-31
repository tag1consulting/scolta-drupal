<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Service\ScoltaAiService;
use Tag1\Scolta\AiClient;

/**
 * Which model actually reaches the AI client on and off the Amazee.ai path.
 *
 * scolta-drupal#187 / scolta-php#251. Amazee model resolution returns LiteLLM
 * **gateway aliases** (`claude-4-5-sonnet`) that only the Amazee proxy accepts.
 * They used to be written straight into `scolta.settings:ai_model` — the key an
 * administrator uses to name a provider-native model — unconditionally, so the
 * write clobbered an explicit choice as well as the shipped default. Once the
 * trial expired or a direct provider key was configured, `ai_provider` became
 * `anthropic` while `ai_model` still held an alias Anthropic does not
 * recognise, and AI degraded permanently behind a generic `ai_error`.
 *
 * These tests build a real ScoltaAiService over the site's own config and
 * state, so they assert the model that would actually be sent rather than the
 * shape of the source. Kernel rather than unit: the unit job declares
 * `provide: {drupal/core: ...}`, so no part of the framework — and therefore no
 * config factory or state service — exists there. No HTTP request is
 * involved, so KernelTestBase is enough — no need for BrowserTestBase.
 *
 * @group scolta
 */
class AmazeeModelKeySeparationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta']);
  }

  private const GATEWAY_ALIAS = 'claude-4-5-sonnet';
  private const GATEWAY_EXPANSION_ALIAS = 'claude-3-5-haiku';
  private const ADMIN_CHOICE = 'claude-opus-4-1-20250805';
  private const ADMIN_EXPANSION_CHOICE = 'claude-haiku-4-5-20251001';

  // ---------------------------------------------------------------------------
  // What reaches the AI client.
  // ---------------------------------------------------------------------------

  /**
   * While Amazee is effective, the gateway alias is the model that is sent.
   */
  public function testGatewayAliasIsSentWhileAmazeeCredentialsAreEffective(): void {
    $this->storeAmazeeCredentials();
    $this->setModels(AiClient::DEFAULT_MODEL, '', self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS);

    $config = $this->service()->getConfig();

    $this->assertSame('openai', $config->aiProvider, 'The Amazee gateway is OpenAI-compatible');
    $this->assertSame('https://llm.test.amazee.ai', $config->aiBaseUrl);
    $this->assertSame(
      self::GATEWAY_ALIAS,
      $config->aiModel,
      'The gateway must be sent the alias it resolved, read from amazee_model'
    );
    $this->assertSame(
      self::GATEWAY_EXPANSION_ALIAS,
      $config->aiExpansionModel,
      'The gateway expansion model must come from amazee_expansion_model'
    );
  }

  /**
   * The expire-then-switch regression: the alias never outlives the gateway.
   *
   * The site was provisioned onto Amazee, then the trial expired (credentials
   * gone) and the operator went back to a direct Anthropic key. The retained
   * gateway alias must not be what the Anthropic API is asked for — that is the
   * exact state that produced a permanent ai_error on the Athenaeum demo.
   */
  public function testOperatorModelIsEffectiveOnceAmazeeCredentialsAreGone(): void {
    $this->clearAmazeeCredentials();
    // The aliases outlive the connection in config; what must not outlive it
    // is their reaching a provider that does not understand them.
    $this->setModels(self::ADMIN_CHOICE, '', self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS, 'anthropic');

    $config = $this->service()->getConfig();

    $this->assertSame('anthropic', $config->aiProvider);
    $this->assertSame(
      self::ADMIN_CHOICE,
      $config->aiModel,
      'A retained gateway alias must never be sent to a direct provider'
    );
    $this->assertSame(
      '',
      $config->aiExpansionModel,
      'The retained gateway expansion alias must not leak either'
    );
  }

  /**
   * An operator's native expansion model is not leaked to the gateway.
   *
   * An empty expansion model means "use the main model", which on the Amazee
   * path is the gateway alias. Passing the operator's provider-native expansion
   * ID through instead would have the gateway reject every expansion call.
   */
  public function testNativeExpansionModelIsNotSentToTheGateway(): void {
    $this->storeAmazeeCredentials();
    $this->setModels(AiClient::DEFAULT_MODEL, self::ADMIN_EXPANSION_CHOICE, self::GATEWAY_ALIAS, '');

    $this->assertSame('', $this->service()->getConfig()->aiExpansionModel);
  }

  /**
   * With nothing resolved, the dated default stays — what the degrade guard in
   * createClient() keys on, rather than an alias-shaped guess.
   */
  public function testUnresolvedGatewayModelLeavesTheDatedDefaultInPlace(): void {
    $this->storeAmazeeCredentials();
    $this->setModels(AiClient::DEFAULT_MODEL, '', '', '');

    $this->assertSame(AiClient::DEFAULT_MODEL, $this->service()->getConfig()->aiModel);
  }

  // ---------------------------------------------------------------------------
  // What model resolution persists.
  // ---------------------------------------------------------------------------

  /**
   * Resolution writes the aliases to the gateway keys and nothing else.
   */
  public function testResolutionWritesOnlyTheGatewayKeys(): void {
    $this->storeAmazeeCredentials();
    $this->setModels(AiClient::DEFAULT_MODEL, '', '', '');

    $this->persistResolvedModels(self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS);
    $config = $this->config('scolta.settings');

    $this->assertSame(self::GATEWAY_ALIAS, $config->get('amazee_model'));
    $this->assertSame(self::GATEWAY_EXPANSION_ALIAS, $config->get('amazee_expansion_model'));
    $this->assertSame(AiClient::DEFAULT_MODEL, $config->get('ai_model'));
    $this->assertSame('', $config->get('ai_expansion_model'));
  }

  /**
   * An explicit administrator model choice survives a provisioning run.
   *
   * This is the acceptance criterion the old callback failed outright: it wrote
   * ai_model unconditionally, so a hand-picked provider-native model was gone
   * the first time resolution ran.
   */
  public function testExplicitAdministratorModelSurvivesResolution(): void {
    $this->storeAmazeeCredentials();
    $this->setModels(self::ADMIN_CHOICE, self::ADMIN_EXPANSION_CHOICE, '', '');

    $this->persistResolvedModels(self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS);
    $config = $this->config('scolta.settings');

    $this->assertSame(self::ADMIN_CHOICE, $config->get('ai_model'), 'ai_model must never be overwritten');
    $this->assertSame(
      self::ADMIN_EXPANSION_CHOICE,
      $config->get('ai_expansion_model'),
      'ai_expansion_model must never be overwritten'
    );
    $this->assertSame(self::GATEWAY_ALIAS, $config->get('amazee_model'));
  }

  /**
   * An empty resolved name is skipped rather than blanking a stored alias.
   */
  public function testEmptyResolvedNamesAreNotPersisted(): void {
    $this->storeAmazeeCredentials();
    $this->setModels(AiClient::DEFAULT_MODEL, '', self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS);

    $this->persistResolvedModels('', '');
    $config = $this->config('scolta.settings');

    $this->assertSame(self::GATEWAY_ALIAS, $config->get('amazee_model'));
    $this->assertSame(self::GATEWAY_EXPANSION_ALIAS, $config->get('amazee_expansion_model'));
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Build a service that reads the config and state as they stand now.
   *
   * Constructed rather than pulled from the container: buildConfig() runs once,
   * in the constructor, so a container instance created before these tests
   * changed anything would answer from the state it booted with. The credential
   * store is passed because it is the only path to the stored credentials —
   * it decrypts the token, and without it the service resolves as an
   * unconnected site.
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

  /**
   * Set the operator-facing and gateway-scoped model keys together.
   *
   * The provider defaults to 'amazee' because most of these cases are about
   * what the gateway is sent, and the gateway is only in play when it is the
   * selected provider. The one case that is about life after the gateway
   * passes 'anthropic'.
   */
  private function setModels(string $aiModel, string $aiExpansionModel, string $amazeeModel, string $amazeeExpansionModel, string $provider = 'amazee'): void {
    \Drupal::configFactory()->getEditable('scolta.settings')
      ->set('ai_provider', $provider)
      ->set('ai_model', $aiModel)
      ->set('ai_expansion_model', $aiExpansionModel)
      ->set('amazee_model', $amazeeModel)
      ->set('amazee_expansion_model', $amazeeExpansionModel)
      ->save();
  }

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
   * Invoke the AutoProvisioner $onModelsResolved callback directly.
   */
  private function persistResolvedModels(string $aiModel, string $aiExpansionModel): void {
    // ReflectionMethod ignores visibility since PHP 8.1 (the package floor).
    $method = new \ReflectionMethod(ScoltaAiService::class, 'persistResolvedAmazeeModels');
    $method->invoke($this->service(), $aiModel, $aiExpansionModel);
  }

}
