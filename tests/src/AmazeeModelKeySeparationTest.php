<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\Service\ScoltaAiService;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;
use Tag1\Scolta\AiClient;

/**
 * Amazee gateway model aliases are kept out of the operator-facing model keys.
 *
 * scolta-drupal#187 / scolta-php#251. Amazee model resolution returns LiteLLM
 * **gateway aliases** (`claude-4-5-sonnet`) that only the Amazee proxy accepts.
 * They used to be written straight into `scolta.settings:ai_model` — the key an
 * administrator uses to name a provider-native model — unconditionally, so the
 * write clobbered an explicit choice as well as the shipped default. Once the
 * trial expired or a direct provider key was configured, `ai_provider` became
 * `anthropic` while `ai_model` still held an alias Anthropic does not recognise,
 * and AI degraded permanently behind a generic `ai_error`.
 *
 * Gateway aliases now live in `amazee_model` / `amazee_expansion_model`,
 * written only by resolution and read only while Amazee credentials are the
 * effective key.
 *
 * These tests drive the real ScoltaAiService against mocked Drupal config and
 * state, so they assert the model that would actually reach the AI client
 * rather than the shape of the source.
 */
class AmazeeModelKeySeparationTest extends TestCase {

  /**
   * The shipped dated default — a provider-native Anthropic ID.
   */
  private const DATED_DEFAULT = 'claude-sonnet-4-5-20250929';

  /**
   * What the Amazee gateway's /model/info returns: gateway-scoped aliases.
   */
  private const GATEWAY_ALIAS = 'claude-4-5-sonnet';
  private const GATEWAY_EXPANSION_ALIAS = 'claude-3-5-haiku';

  /**
   * A provider-native model an administrator picked by hand.
   */
  private const ADMIN_CHOICE = 'claude-opus-4-1-20250805';

  private const CREDENTIALS = [
    'litellm_token' => 'sk-stored-token',
    'litellm_api_url' => 'https://llm.test.amazee.ai',
    'region' => 'test-region',
  ];

  /**
   * Writes captured from the editable scolta.settings config.
   *
   * @var array<string, mixed>
   */
  private array $written = [];

  private string|false $originalEnvKey;

  protected function setUp(): void {
    // getApiKey() reads settings.php through Settings::get(), whose singleton
    // is unset without a Drupal bootstrap; constructing it registers it.
    new Settings([]);

    // An explicit key short-circuits the Amazee path entirely, so a developer
    // machine that exports one must not silently skip these assertions.
    $this->originalEnvKey = getenv('SCOLTA_API_KEY');
    putenv('SCOLTA_API_KEY');

    $this->written = [];
  }

  protected function tearDown(): void {
    if (is_string($this->originalEnvKey)) {
      putenv('SCOLTA_API_KEY=' . $this->originalEnvKey);
    }
  }

  // -------------------------------------------------------------------------
  // What reaches the AI client.
  // -------------------------------------------------------------------------

  /**
   * While Amazee is effective, the gateway alias is the model that is sent.
   */
  public function testGatewayAliasIsSentWhileAmazeeCredentialsAreEffective(): void {
    $service = $this->service(
      [
        'ai_model' => self::DATED_DEFAULT,
        'ai_expansion_model' => '',
        'amazee_model' => self::GATEWAY_ALIAS,
        'amazee_expansion_model' => self::GATEWAY_EXPANSION_ALIAS,
      ],
      self::CREDENTIALS,
    );

    $config = $service->getConfig();
    $this->assertSame('openai', $config->aiProvider, 'The Amazee gateway is OpenAI-compatible');
    $this->assertSame(self::CREDENTIALS['litellm_api_url'], $config->aiBaseUrl);
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
   * exact state that produced a permanent `ai_error` on the Athenaeum demo.
   */
  public function testOperatorModelIsEffectiveOnceAmazeeCredentialsAreGone(): void {
    $service = $this->service(
      [
        'ai_provider' => 'anthropic',
        'ai_model' => self::ADMIN_CHOICE,
        'ai_expansion_model' => '',
        // Retained, so flipping back to Amazee needs no re-provisioning.
        'amazee_model' => self::GATEWAY_ALIAS,
        'amazee_expansion_model' => self::GATEWAY_EXPANSION_ALIAS,
      ],
      NULL,
    );

    $config = $service->getConfig();
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
    $service = $this->service(
      [
        'ai_model' => self::DATED_DEFAULT,
        'ai_expansion_model' => 'claude-haiku-4-5-20251001',
        'amazee_model' => self::GATEWAY_ALIAS,
        'amazee_expansion_model' => '',
      ],
      self::CREDENTIALS,
    );

    $this->assertSame('', $service->getConfig()->aiExpansionModel);
  }

  /**
   * With nothing resolved, the dated default stays — what the degrade guard
   * in createClient() keys on, rather than an alias-shaped guess.
   */
  public function testUnresolvedGatewayModelLeavesTheDatedDefaultInPlace(): void {
    $service = $this->service(
      [
        'ai_model' => self::DATED_DEFAULT,
        'ai_expansion_model' => '',
        'amazee_model' => '',
        'amazee_expansion_model' => '',
      ],
      self::CREDENTIALS,
    );

    $this->assertSame(self::DATED_DEFAULT, $service->getConfig()->aiModel);
    $this->assertSame(AiClient::DEFAULT_MODEL, $service->getConfig()->aiModel);
  }

  // -------------------------------------------------------------------------
  // What model resolution persists.
  // -------------------------------------------------------------------------

  /**
   * Resolution writes the aliases to the gateway keys and nothing else.
   */
  public function testResolutionWritesOnlyTheGatewayKeys(): void {
    $service = $this->service(
      [
        'ai_model' => self::DATED_DEFAULT,
        'ai_expansion_model' => '',
        'amazee_model' => '',
        'amazee_expansion_model' => '',
      ],
      self::CREDENTIALS,
    );

    $this->persistResolvedModels($service, self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS);

    $this->assertSame(
      [
        'amazee_model' => self::GATEWAY_ALIAS,
        'amazee_expansion_model' => self::GATEWAY_EXPANSION_ALIAS,
      ],
      $this->written,
      'Model resolution must touch the gateway-scoped keys and no others'
    );
  }

  /**
   * An explicit administrator model choice survives a provisioning run.
   *
   * This is the acceptance criterion the old callback failed outright: it wrote
   * ai_model unconditionally, so a hand-picked provider-native model was gone
   * the first time resolution ran.
   */
  public function testExplicitAdministratorModelSurvivesResolution(): void {
    $service = $this->service(
      [
        'ai_model' => self::ADMIN_CHOICE,
        'ai_expansion_model' => 'claude-haiku-4-5-20251001',
        'amazee_model' => '',
        'amazee_expansion_model' => '',
      ],
      self::CREDENTIALS,
    );

    $this->persistResolvedModels($service, self::GATEWAY_ALIAS, self::GATEWAY_EXPANSION_ALIAS);

    $this->assertArrayNotHasKey('ai_model', $this->written, 'ai_model must never be overwritten');
    $this->assertArrayNotHasKey('ai_expansion_model', $this->written, 'ai_expansion_model must never be overwritten');
  }

  /**
   * An empty resolved name is skipped rather than blanking a stored alias.
   */
  public function testEmptyResolvedNamesAreNotPersisted(): void {
    $service = $this->service(
      ['ai_model' => self::DATED_DEFAULT, 'amazee_model' => self::GATEWAY_ALIAS],
      self::CREDENTIALS,
    );

    $this->persistResolvedModels($service, '', '');

    $this->assertSame([], $this->written);
  }

  // -------------------------------------------------------------------------
  // The config keys themselves.
  // -------------------------------------------------------------------------

  public function testGatewayKeysAreDeclaredWithEmptyDefaults(): void {
    $root = dirname(__DIR__, 2);
    $install = Yaml::parseFile($root . '/config/install/scolta.settings.yml');
    $schema = Yaml::parseFile($root . '/config/schema/scolta.schema.yml');

    foreach (['amazee_model', 'amazee_expansion_model'] as $key) {
      $this->assertArrayHasKey($key, $install, "{$key} must ship an install default");
      $this->assertSame('', $install[$key], "{$key} must default to empty — nothing is resolved on a fresh site");
      $this->assertSame(
        'string',
        $schema['scolta.settings']['mapping'][$key]['type'] ?? NULL,
        "{$key} must be declared as a string in the config schema"
      );
    }

    $this->assertSame(
      self::DATED_DEFAULT,
      $install['ai_model'],
      'The operator-facing default stays a provider-native Anthropic ID'
    );
  }

  /**
   * The trial-provisioning form writes gateway keys too.
   *
   * AmazeeSettingsForm::submitStartTrial() is the second binding site for the
   * same resolved names; leaving it on ai_model would reintroduce the bug for
   * anyone who starts a trial from the admin UI. Its old "only when still at
   * the shipped default" guard is dead under the new shape and must be gone —
   * there is no operator-chosen value in a gateway-scoped key to protect.
   */
  public function testTrialProvisioningFormWritesTheGatewayKeys(): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/src/Form/AmazeeSettingsForm.php');

    $this->assertStringContainsString("\$config->set('amazee_model', \$result->aiModel)", $source);
    $this->assertStringContainsString("\$config->set('amazee_expansion_model', \$result->aiExpansionModel)", $source);
    $this->assertStringNotContainsString("set('ai_model'", $source, 'The trial form must not write the operator-facing ai_model');
    $this->assertStringNotContainsString("set('ai_expansion_model'", $source, 'The trial form must not write the operator-facing ai_expansion_model');
    $this->assertStringNotContainsString(
      'DEFAULT_AI_MODEL',
      $source,
      'The shipped-default guard is dead once aliases have their own key'
    );
  }

  // -------------------------------------------------------------------------
  // The migration hook.
  // -------------------------------------------------------------------------

  /**
   * The update hook exists and is scoped to credentialed, unmigrated sites.
   *
   * Behaviour is asserted against a real Drupal in
   * tests/src/Functional/AmazeeModelMigrationTest.php; this pins the guards
   * that keep it from touching a site whose ai_model is an operator's own.
   */
  public function testMigrationHookIsGuarded(): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/scolta.install');

    $this->assertStringContainsString('function scolta_update_10003()', $source);
    $this->assertStringContainsString("\\Drupal::state()->get('scolta.amazee.credentials')", $source);
    $this->assertStringContainsString("\$config->get('amazee_model') ?? ''", $source);
    $this->assertStringContainsString('ScoltaSettingsForm::DEFAULT_AI_MODEL', $source);
    $this->assertStringContainsString(
      "\$config->set('amazee_model', \$strandedModel)",
      $source,
      'The migration must move the alias rather than discard it'
    );
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  /**
   * Build a real ScoltaAiService over mocked config and state.
   *
   * @param array<string, mixed> $settings
   *   scolta.settings values. Missing AI keys fall back to install defaults.
   * @param array<string, string>|null $credentials
   *   Stored Amazee credentials, or NULL for a site with none.
   */
  private function service(array $settings, ?array $credentials): ScoltaAiService {
    $settings += [
      'ai_provider' => 'anthropic',
      'ai_model' => self::DATED_DEFAULT,
      'ai_expansion_model' => '',
      'amazee_model' => '',
      'amazee_expansion_model' => '',
      'site_name' => 'Test site',
    ];

    $scoltaSettings = $this->createMock(ImmutableConfig::class);
    $scoltaSettings->method('get')->willReturnCallback(
      fn (?string $key = NULL) => ($key === NULL || $key === '') ? $settings : ($settings[$key] ?? NULL),
    );

    $systemSite = $this->createMock(ImmutableConfig::class);
    $systemSite->method('get')->willReturn('Test site');

    $editable = $this->createMock(Config::class);
    $editable->method('get')->willReturnCallback(
      fn (?string $key = NULL) => ($key === NULL || $key === '') ? $settings : ($settings[$key] ?? NULL),
    );
    $editable->method('set')->willReturnCallback(
      function (string $key, $value) use ($editable) {
        $this->written[$key] = $value;
        return $editable;
      },
    );
    $editable->method('save')->willReturn($editable);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(
      fn (string $name) => $name === 'system.site' ? $systemSite : $scoltaSettings,
    );
    $configFactory->method('getEditable')->willReturn($editable);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      fn (string $key, $default = NULL) => $key === 'scolta.amazee.credentials' ? $credentials : $default,
    );

    return new ScoltaAiService(new Client(), $configFactory, new NullLogger(), $state);
  }

  /**
   * Invoke the AutoProvisioner $onModelsResolved callback directly.
   */
  private function persistResolvedModels(ScoltaAiService $service, string $aiModel, string $aiExpansionModel): void {
    // ReflectionMethod ignores visibility since PHP 8.1 (the package floor).
    $method = new \ReflectionMethod(ScoltaAiService::class, 'persistResolvedAmazeeModels');
    $method->invoke($service, $aiModel, $aiExpansionModel);
  }

}
