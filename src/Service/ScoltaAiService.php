<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\AiProvider\Amazee\BudgetExceededHandler;
use Drupal\scolta\Cache\DrupalCacheDriver;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Tag1\Scolta\AiClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner;
use Tag1\Scolta\AiProvider\Amazee\BudgetAwareProviderDecorator;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Wraps Tag1\Scolta\AiClient with Drupal config injection.
 *
 * Registered as the 'scolta.ai_service' service. Controllers and
 * commands use this instead of constructing AiClient directly.
 *
 * Supports a three-path AI strategy:
 * 1. Amazee.ai (zero-config default): When Amazee credentials are stored in
 *    Drupal State and no explicit key or 'drupal_ai' provider is configured,
 *    buildConfig() injects the Amazee LiteLLM token and routes through the
 *    built-in AiClient as an OpenAI-compatible endpoint.
 * 2. Drupal AI module (opt-in): When the admin explicitly selects 'drupal_ai'
 *    as the provider, tryFrameworkAi() routes through the Drupal AI module's
 *    plugin manager. Amazee credentials are NOT injected in this path —
 *    the Drupal AI module manages its own provider, key, and model.
 * 3. Built-in AiClient (fallback): When neither of the above applies, direct
 *    HTTP calls are made with the configured provider and API key.
 */
class ScoltaAiService extends AiServiceAdapter {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private ClientInterface $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * The Drupal state service (reads Amazee.ai credentials at request time).
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private StateInterface $state;

  /**
   * Handles Amazee.ai budget-exceeded notices. Null when Amazee is not active.
   *
   * @var \Drupal\scolta\AiProvider\Amazee\BudgetExceededHandler|null
   */
  private ?BudgetExceededHandler $budgetHandler;

  /**
   * Amazee credential storage used for lazy auto-provisioning.
   *
   * Protected so createKeyExpiryRecovery() (and test subclasses that inject a
   * stubbed AmazeeClient) can reuse the same store the provisioner writes to.
   *
   * @var \Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface|null
   */
  protected ?ConfigStorageInterface $amazeeConfigStorage;

  /**
   * Cache backend used for the KeyExpiryRecovery markers and AI response cache.
   *
   * The SAME backend HealthController hands to HealthChecker, so a recorded
   * auth failure makes both /health report the truth and the admin notice
   * surface the re-authentication prompt.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|null
   */
  private ?CacheBackendInterface $cache;

  /**
   * The Amazee key-expiry recovery, wired only on the managed Amazee.ai path.
   *
   * Held so the admin-notice surface can read the persistent re-authentication
   * marker (isAmazeeReauthNeeded()) and clear it after a successful reconnect.
   *
   * @var \Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery|null
   */
  private ?KeyExpiryRecovery $keyExpiryRecovery = NULL;

  /**
   * The Drupal AI module's provider plugin manager, when installed.
   *
   * Injected optionally ('@?ai.provider') so the service definition works
   * whether or not the AI module exists. Typed object because the
   * \Drupal\ai\AiProviderPluginManager class is absent without the module.
   *
   * @var object|null
   */
  private ?object $aiProviderManager;

  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    LoggerInterface $logger,
    StateInterface $state,
    ?BudgetExceededHandler $budgetHandler = NULL,
    ?ConfigStorageInterface $amazeeConfigStorage = NULL,
    ?object $aiProviderManager = NULL,
    ?CacheBackendInterface $cache = NULL,
  ) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
    $this->logger = $logger;
    // Assign state before parent::__construct so buildConfig() can read it.
    $this->state = $state;
    $this->budgetHandler = $budgetHandler;
    $this->amazeeConfigStorage = $amazeeConfigStorage;
    $this->aiProviderManager = $aiProviderManager;
    $this->cache = $cache;

    parent::__construct($this->buildConfig());

    $this->maybeWireKeyExpiryRecovery();
  }

  /**
   * Wire Amazee key-expiry recovery — but only on the managed Amazee.ai path.
   *
   * When the stored Amazee.ai credentials stop being accepted, the next AI
   * call fails authentication and (before this) AI silently died while /health
   * kept reporting it configured. Wiring KeyExpiryRecovery makes that failure
   * degrade cleanly: it records the failure so /health reports AI as degraded
   * and sets a persistent marker so the admin UI prompts the operator to
   * re-authenticate (reconnect/upgrade) with Amazee.ai. The stored credentials
   * are left in place.
   *
   * Policy: an Amazee.ai credential that is no longer accepted must route the
   * operator to the re-authentication/upgrade flow. It must NEVER cause a new
   * Amazee.ai connection to be obtained behind the operator's back — recovery
   * is a deliberate, admin-initiated step through AmazeeSettingsForm.
   *
   * Recovery is wired ONLY on the managed Amazee.ai path. An explicit user key
   * (SCOLTA_API_KEY / settings.php) or the 'drupal_ai' provider must never be
   * touched: a failing explicit key is the user's to fix, and the Drupal AI
   * module owns its own credentials. The base AiServiceAdapter wires recovery
   * unconditionally once set, so the path gate lives here at wire time
   * (mirroring scolta-node's explicit-key guard).
   *
   * @since 1.0.4
   * @stability experimental
   */
  private function maybeWireKeyExpiryRecovery(): void {
    if ($this->cache === NULL || $this->amazeeConfigStorage === NULL) {
      return;
    }
    if ($this->getApiKey() !== '' || $this->getConfig()->aiProvider === 'drupal_ai') {
      return;
    }

    $this->keyExpiryRecovery = $this->createKeyExpiryRecovery(new DrupalCacheDriver($this->cache));
    $this->setKeyExpiryRecovery($this->keyExpiryRecovery);
  }

  /**
   * Build the KeyExpiryRecovery over the Amazee credential store and cache.
   *
   * Overridable so tests can inject a pre-configured AmazeeClient instead of
   * the live control-plane client.
   *
   * @since 1.0.4
   * @stability experimental
   */
  protected function createKeyExpiryRecovery(CacheDriverInterface $cache): KeyExpiryRecovery {
    return new KeyExpiryRecovery(
      storage: $this->amazeeConfigStorage,
      cache: $cache,
      logger: $this->logger,
    );
  }

  /**
   * Whether the stored Amazee.ai credentials need admin re-authentication.
   *
   * True once an auth-class failure of the managed Amazee.ai credentials has
   * been recorded — scolta-php sets a persistent marker on that path. Admin
   * UIs read this to prompt the operator to reconnect/upgrade with Amazee.ai.
   * False on the explicit-key and 'drupal_ai' paths (recovery is not wired
   * there) and whenever the marker is unset. A cache-marker read only, never a
   * live API call, so it is safe to call on every admin page load.
   *
   * @since 1.0.5
   * @stability experimental
   */
  public function isAmazeeReauthNeeded(): bool {
    return $this->keyExpiryRecovery?->isUpgradeNeeded() ?? FALSE;
  }

  /**
   * Clear the re-authentication marker after a successful reconnect.
   *
   * Called once the operator has completed the Amazee.ai email-verification
   * flow and fresh credentials are stored, so the admin notice goes away.
   *
   * @since 1.0.5
   * @stability experimental
   */
  public function clearAmazeeReauthNeeded(): void {
    $this->keyExpiryRecovery?->clearUpgradeNeeded();
  }

  /**
   * Build ScoltaConfig from Drupal config + settings.
   *
   * Flattens the nested scoring and display config into top-level keys
   * for ScoltaConfig::fromArray(), removes pagefind settings (not needed
   * by the AI client), and injects the API key and site name.
   */
  protected function buildConfig(): ScoltaConfig {
    $drupalConfig = $this->configFactory->get('scolta.settings');
    // Read via get() so settings.php $config['scolta.settings'] overrides
    // apply to AI traffic like any other config consumer.
    $values = $drupalConfig->get() ?? [];

    // Flatten nested scoring config to top-level keys.
    // Top-level keys (e.g. set directly via drush config:set) take precedence
    // over nested values so that explicit programmatic overrides are respected.
    if (isset($values['scoring']) && is_array($values['scoring'])) {
      $scoring = $values['scoring'];
      unset($values['scoring']);
      foreach ($scoring as $key => $value) {
        if (!array_key_exists($key, $values)) {
          $values[$key] = $value;
        }
      }
    }

    // Flatten nested display config to top-level keys.
    // Same precedence rule: a top-level key wins over display.*.
    if (isset($values['display']) && is_array($values['display'])) {
      $display = $values['display'];
      unset($values['display']);
      foreach ($display as $key => $value) {
        if (!array_key_exists($key, $values)) {
          $values[$key] = $value;
        }
      }
    }

    // Remove pagefind config (not relevant to ScoltaConfig).
    unset($values['pagefind']);

    // Explicit key (env / settings.php) takes priority over Amazee credentials
    // so users who configured their own provider are never silently rerouted.
    $explicitKey = $this->getApiKey();
    if ($explicitKey !== '') {
      $values['ai_api_key'] = $explicitKey;
    }
    elseif (($values['ai_provider'] ?? '') !== 'drupal_ai') {
      // Only inject Amazee credentials for built-in providers. When
      // 'drupal_ai' is selected the Drupal AI module manages its own
      // provider, key, and model.
      $amazeeCreds = $this->state->get('scolta.amazee.credentials');
      if (is_array($amazeeCreds) && !empty($amazeeCreds['litellm_token'])) {
        $values['ai_provider'] = 'openai';
        $values['ai_api_key'] = $amazeeCreds['litellm_token'];
        $values['ai_base_url'] = $amazeeCreds['litellm_api_url'] ?? '';
      }
    }

    // Site name fallback to Drupal site name.
    if (empty($values['site_name'])) {
      $values['site_name'] = $this->configFactory->get('system.site')->get('name') ?? '';
    }

    return ScoltaConfig::fromArray($values);
  }

  /**
   * Get the API key from environment variable or Drupal settings.
   *
   * Priority: SCOLTA_API_KEY env var > settings.php scolta.api_key.
   */
  public function getApiKey(): string {
    $envKey = getenv('SCOLTA_API_KEY');
    if ($envKey !== FALSE && $envKey !== '') {
      return $envKey;
    }

    return $this->settingsApiKey();
  }

  /**
   * Read the settings.php API key, coercing anything that is not a string.
   *
   * A site that writes $settings['scolta.api_key'] = getenv('SCOLTA_API_KEY');
   * stores boolean FALSE in every environment that does not define the
   * variable, and Settings::get() hands that back untouched. Returning it from
   * getApiKey(), which declares a string return type, throws a TypeError; and
   * because this service is constructed on nearly every code path, that takes
   * down every Drush command in the environment rather than only the AI ones.
   *
   * An unconfigured key is a supported state that degrades AI gracefully, so a
   * wrongly-typed one degrades the same way instead of being fatal. Both
   * readers go through here so they cannot disagree about the same value.
   */
  private function settingsApiKey(): string {
    $key = Settings::get('scolta.api_key', '');
    if (is_string($key)) {
      return $key;
    }

    $this->logger->warning(
      "The settings.php value \$settings['scolta.api_key'] is a @type, not a string; ignoring it and treating the API key as unconfigured.",
      ['@type' => gettype($key)]
    );

    return '';
  }

  /**
   * Determine the source of the API key.
   *
   * @return string
   *   One of 'amazee', 'env', 'settings', or 'none'.
   */
  public function getApiKeySource(): string {
    $amazeeCreds = $this->state->get('scolta.amazee.credentials');
    if (is_array($amazeeCreds) && !empty($amazeeCreds['litellm_token'])) {
      return 'amazee';
    }

    $envKey = getenv('SCOLTA_API_KEY');
    if ($envKey !== FALSE && $envKey !== '') {
      return 'env';
    }

    $settingsKey = $this->settingsApiKey();
    if ($settingsKey !== '') {
      return 'settings';
    }

    return 'none';
  }

  /**
   * Whether Amazee.ai credentials are currently active.
   */
  public function isAmazeeActive(): bool {
    return $this->getApiKeySource() === 'amazee';
  }

  /**
   * Check if the Drupal AI module is available.
   *
   * The 'ai.provider' plugin manager is injected optionally, so its
   * presence is equivalent to the AI module being installed.
   */
  public function hasDrupalAiModule(): bool {
    return $this->aiProviderManager !== NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Only routes through the Drupal AI module when the admin has explicitly
   * selected 'drupal_ai' as the provider. Merely having the module installed
   * does not change routing — this prevents Amazee.ai and other built-in
   * providers from being silently hijacked by the Drupal AI module.
   */
  protected function tryFrameworkAi(string $systemPrompt, string $userMessage, int $maxTokens): ?string {
    if ($this->getConfig()->aiProvider !== 'drupal_ai') {
      return NULL;
    }

    if (!$this->hasDrupalAiModule()) {
      $this->logger->warning('Drupal AI provider selected but ai module is not installed. Falling back to built-in client.');
      return NULL;
    }

    try {
      return $this->messageViaDrupalAi($systemPrompt, $userMessage, $maxTokens);
    }
    catch (\Exception $e) {
      $this->logger->warning('Drupal AI module message failed, falling back to built-in client: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Only routes through the Drupal AI module when the admin has explicitly
   * selected 'drupal_ai' as the provider. See tryFrameworkAi() for rationale.
   */
  protected function tryFrameworkConversation(string $systemPrompt, array $messages, int $maxTokens): ?string {
    if ($this->getConfig()->aiProvider !== 'drupal_ai') {
      return NULL;
    }

    if (!$this->hasDrupalAiModule()) {
      $this->logger->warning('Drupal AI provider selected but ai module is not installed. Falling back to built-in client.');
      return NULL;
    }

    try {
      return $this->conversationViaDrupalAi($systemPrompt, $messages, $maxTokens);
    }
    catch (\Exception $e) {
      $this->logger->warning('Drupal AI module conversation failed, falling back to built-in client: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Send a single message via the Drupal AI module's service layer.
   *
   * Uses the site's configured default AI provider for chat operations
   * (from the Drupal AI module's settings), not Scolta's own provider config.
   * This respects the Drupal AI module's rate limiting, Key module integration,
   * and hooks.
   */
  protected function messageViaDrupalAi(string $systemPrompt, string $userMessage, int $maxTokens): string {
    /** @var \Drupal\ai\AiProviderPluginManager $pluginManager */
    $pluginManager = $this->aiProviderManager;

    $default = $pluginManager->getDefaultProviderForOperationType('chat');
    if (empty($default) || empty($default['provider_id'])) {
      throw new \RuntimeException('No default AI provider configured in the Drupal AI module for chat operations. Configure a provider at /admin/config/ai/providers.');
    }

    $input = new ChatInput([
      new ChatMessage('system', $systemPrompt),
      new ChatMessage('user', $userMessage),
    ]);

    $provider = $pluginManager->createInstance($default['provider_id']);
    // The third chat() argument is $tags, not options — a token limit set there
    // is silently ignored. max_tokens must go through setConfiguration() before
    // the call (see #163 review).
    $provider->setConfiguration(['max_tokens' => $maxTokens]);
    $response = $provider->chat($input, $default['model_id'] ?? '', ['scolta']);

    return $response->getNormalized()->getText();
  }

  /**
   * Send a multi-turn conversation via the Drupal AI module's service layer.
   *
   * Uses the site's configured default AI provider for chat operations.
   * See messageViaDrupalAi() for details on the service-layer approach.
   */
  protected function conversationViaDrupalAi(string $systemPrompt, array $messages, int $maxTokens): string {
    /** @var \Drupal\ai\AiProviderPluginManager $pluginManager */
    $pluginManager = $this->aiProviderManager;

    $default = $pluginManager->getDefaultProviderForOperationType('chat');
    if (empty($default) || empty($default['provider_id'])) {
      throw new \RuntimeException('No default AI provider configured in the Drupal AI module for chat operations. Configure a provider at /admin/config/ai/providers.');
    }

    $chatMessages = [
      new ChatMessage('system', $systemPrompt),
    ];
    foreach ($messages as $msg) {
      $chatMessages[] = new ChatMessage($msg['role'], $msg['content']);
    }

    $input = new ChatInput($chatMessages);

    $provider = $pluginManager->createInstance($default['provider_id']);
    // See messageViaDrupalAi(): max_tokens goes through setConfiguration(), not
    // the chat() $tags argument (#163 review).
    $provider->setConfiguration(['max_tokens' => $maxTokens]);
    $response = $provider->chat($input, $default['model_id'] ?? '', ['scolta']);

    return $response->getNormalized()->getText();
  }

  /**
   * {@inheritdoc}
   *
   * Converts a budget-exceeded RuntimeException to
   * AmazeeBudgetExceededException, notifies the handler, and re-throws. No-op
   * if the message does not match. Invoked by the base AI methods' catch block.
   */
  protected function handlePossibleBudgetException(\RuntimeException $e): void {
    // isBudgetError() owns the budget-message matching (including walking
    // the exception chain) so the adapter never duplicates scolta-php's
    // private budget-string constant.
    if (!BudgetAwareProviderDecorator::isBudgetError($e)) {
      return;
    }
    $budgetException = new AmazeeBudgetExceededException($e);
    $this->budgetHandler?->handle($budgetException);
    throw $budgetException;
  }

  /**
   * {@inheritdoc}
   *
   * Attempts lazy auto-provisioning when no API key is configured. This
   * covers cases where the install-hook provisioning attempt failed (e.g.
   * no network at install time). If provisioning succeeds, the client is
   * built with the freshly stored credentials.
   */
  protected function createClient(): AiClient {
    if ($this->getApiKeySource() === 'none' && $this->amazeeConfigStorage !== NULL) {
      AutoProvisioner::ensureAiAvailable(
        $this->amazeeConfigStorage,
        hasExplicitApiKey: FALSE,
        onModelsResolved: function (string $aiModel, string $aiExpansionModel): void {
          $config = $this->configFactory->getEditable('scolta.settings');
          if ($aiModel !== '') {
            $config->set('ai_model', $aiModel);
          }
          if ($aiExpansionModel !== '') {
            $config->set('ai_expansion_model', $aiExpansionModel);
          }
          $config->save();
        },
        // Tell AutoProvisioner whether a genuinely resolved model is already
        // persisted. When credentials are stored but model resolution never
        // succeeded (the provision's /model/info step failed), this returns
        // FALSE and the provisioner re-resolves against the ALREADY-STORED key
        // — self-healing the half-provisioned state — instead of no-opping
        // forever on the stored credentials. The predicate MUST treat the
        // shipped dated default as unresolved: createClient() only ran because
        // getApiKeySource() === 'none', and config still carries the install
        // default model, so a naive "ai_model is non-empty" check would always
        // report TRUE and the self-heal would never fire.
        hasResolvedModels: fn (): bool => self::modelIsResolved(
          $this->configFactory->get('scolta.settings')->get('ai_model'),
        ),
      );
      // Provisioning may have just stored Amazee credentials. If it did but
      // model resolution still has not succeeded, the only model in config is
      // the shipped dated default — which the Amazee LiteLLM gateway rejects
      // with HTTP 400, breaking AI permanently and silently. Degrade instead:
      // a key-less client makes the call throw ApiKeyMissingException, which
      // the AI controllers turn into an unexpanded/no-summary HTTP 200 (same
      // path as a wholly unconfigured site). The state self-heals on a later
      // request once /model/info recovers (hasResolvedModels reports FALSE →
      // re-resolve). Mirrors scolta-node's AmazeeAiService::buildClient().
      if ($this->isAmazeeActive()
        && !self::modelIsResolved($this->configFactory->get('scolta.settings')->get('ai_model'))) {
        return $this->createDegradedClient();
      }
      // Re-read state in case provisioning just stored new credentials.
      return new AiClient($this->buildConfig()->toAiClientConfig(), $this->httpClient);
    }
    return new AiClient($this->getConfig()->toAiClientConfig(), $this->httpClient);
  }

  /**
   * Whether a genuinely resolved AI model name is persisted.
   *
   * Reports FALSE for the unresolved state: a NULL/empty model, or the shipped
   * dated default (`AiClient::DEFAULT_MODEL`, identical to
   * `ScoltaSettingsForm::DEFAULT_AI_MODEL`), which is what config carries
   * before Amazee model resolution writes a real name (e.g.
   * `claude-sonnet-4-5`). The dated default is precisely the value the Amazee
   * gateway rejects with HTTP 400, so it must never count as "resolved" —
   * otherwise the self-heal in createClient() becomes a no-op that ships the
   * bug.
   *
   * @since 1.0.4
   * @stability experimental
   */
  protected static function modelIsResolved(?string $aiModel): bool {
    return $aiModel !== NULL
      && $aiModel !== ''
      && $aiModel !== AiClient::DEFAULT_MODEL;
  }

  /**
   * Build a key-less client that degrades rather than calling the gateway.
   *
   * Used on the Amazee path when credentials are stored but no model is
   * resolved: stripping the API key makes AiClient throw ApiKeyMissingException
   * on the first call, which the AI controllers degrade to an HTTP 200
   * unexpanded/no-summary response — never the HTTP 400 the dated default would
   * trigger against the LiteLLM gateway.
   *
   * @since 1.0.4
   * @stability experimental
   */
  protected function createDegradedClient(): AiClient {
    $config = $this->getConfig()->toAiClientConfig();
    $config['api_key'] = '';
    return new AiClient($config, $this->httpClient);
  }

}
