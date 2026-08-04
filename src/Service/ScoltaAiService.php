<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\scolta\AiProvider\Amazee\BudgetExceededHandler;
use Drupal\scolta\Cache\DrupalCacheDriver;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Tag1\Scolta\AiClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner;
use Tag1\Scolta\AiProvider\Amazee\ProvenanceAwareConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\BudgetAwareProviderDecorator;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Config\AmazeeCredentials;
use Tag1\Scolta\Config\ApiKeyResolver;
use Tag1\Scolta\Config\ResolvedApiKey;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Wraps Tag1\Scolta\AiClient with Drupal config injection.
 *
 * Registered as the 'scolta.ai_service' service. Controllers and
 * commands use this instead of constructing AiClient directly.
 *
 * Supports a three-path AI strategy:
 * 1. Amazee.ai (opt-in): When the operator has selected 'amazee' as the AI
 *    provider AND a connection is stored in Drupal State, buildConfig()
 *    injects the Amazee LiteLLM token, the gateway-scoped model alias from
 *    'amazee_model', and routes through the built-in AiClient as an
 *    OpenAI-compatible endpoint. The operator-facing 'ai_model' is left alone
 *    on this path so a later switch to a direct provider key still works.
 *    Selecting any other provider leaves the managed gateway out of AI
 *    traffic entirely, whatever is stored — and an explicit key still wins
 *    over everything, as it always did.
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
   * Handles Amazee.ai budget-exceeded notices. Null when Amazee is not active.
   *
   * @var \Drupal\scolta\AiProvider\Amazee\BudgetExceededHandler|null
   */
  private ?BudgetExceededHandler $budgetHandler;

  /**
   * Amazee credential storage, read for the self-heal on the lazy-init path.
   *
   * Nothing establishes a connection here. The store is read so model names
   * can be re-resolved against a key that is already on disk; the two paths
   * that establish a connection are both explicit operator actions on the
   * Amazee.ai settings form.
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

  /**
   * Constructs the service.
   *
   * The Drupal state service is not a parameter: Amazee.ai credentials live in
   * state but are read through $amazeeConfigStorage, which decrypts the token
   * that DrupalConfigStorage::store() encrypted. Reading state here is what
   * sent ciphertext to the gateway as a bearer token.
   */
  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    LoggerInterface $logger,
    ?BudgetExceededHandler $budgetHandler = NULL,
    ?ConfigStorageInterface $amazeeConfigStorage = NULL,
    ?object $aiProviderManager = NULL,
    ?CacheBackendInterface $cache = NULL,
  ) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
    $this->logger = $logger;
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
    if (!$this->resolveApiKey()->isAmazee()) {
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
   * flow and fresh credentials are stored, so the admin notice goes away, and
   * when the operator switches the AI provider away from the managed gateway.
   *
   * Falls back to an unwired recovery so it also works off the managed-gateway
   * path. Wiring happens at construction time and only while the gateway is
   * the effective source; a marker left behind by a connection that is being
   * abandoned has to be clearable exactly then, or it outlives the thing it
   * describes.
   *
   * @since 1.0.5
   * @stability experimental
   */
  public function clearAmazeeReauthNeeded(): void {
    $this->recoveryForClearing()?->clearUpgradeNeeded();
  }

  /**
   * Clear the recorded auth-failure marker.
   *
   * The counterpart of the upgrade-needed marker: it is what makes /health
   * report AI as degraded. It ages out on its own and clears on the next
   * successful AI call, but neither happens for a site that has just removed
   * the connection the failure belonged to — there is nothing left to make a
   * successful call with, so the stale "AI is failing authentication" report
   * would stand for its full retention window.
   *
   * @since 1.1.0
   * @stability experimental
   */
  public function clearAmazeeAuthFailure(): void {
    $this->recoveryForClearing()?->clearAuthFailure();
  }

  /**
   * A recovery usable for clearing markers, wired or not.
   *
   * Returns the wired instance when the managed gateway is the effective
   * source, and otherwise builds one over the same store and cache bin, so
   * clearing works regardless of the path. NULL only when the service was
   * constructed without a cache or credential store, where no marker can
   * exist in the first place.
   */
  private function recoveryForClearing(): ?KeyExpiryRecovery {
    if ($this->keyExpiryRecovery !== NULL) {
      return $this->keyExpiryRecovery;
    }
    if ($this->cache === NULL || $this->amazeeConfigStorage === NULL) {
      return NULL;
    }

    return $this->createKeyExpiryRecovery(new DrupalCacheDriver($this->cache));
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

    // One resolution, shared with every surface that reports on it. The key,
    // its source and the provider that goes with it arrive together, so the
    // settings form, /health and Drush cannot describe this differently from
    // the client that is about to send it (scolta-php#252). Explicit keys
    // still win over Amazee credentials, which is what the resolver's
    // canonical precedence encodes; what changed is that the reporting
    // surfaces now read this answer instead of computing a second one.
    $resolved = $this->resolveApiKey();
    if ($resolved->isConfigured()) {
      $values['ai_api_key'] = $resolved->key;
    }
    if ($resolved->isAmazee()) {
      $values['ai_provider'] = $resolved->provider;
      $values['ai_base_url'] = $resolved->baseUrl;
      // Amazee is the effective provider for this request, so the model
      // sent to the gateway comes from the gateway-scoped keys, never from
      // the operator-facing ones. amazee_model holds a LiteLLM alias
      // (e.g. 'claude-4-5-sonnet') that only the gateway understands, and
      // ai_model holds a provider-native ID that only the direct provider
      // API understands; keeping them apart is what lets a site move
      // between the two without losing either (scolta-php#251).
      //
      // ai_model is left in place when nothing has been resolved yet, so
      // the shipped dated default remains and createClient()'s degrade
      // guard — which reads the same gateway-scoped key — catches it
      // before the gateway can reject it with HTTP 400. The expansion
      // model is replaced unconditionally: an operator-chosen native
      // expansion ID must not leak to the gateway, and an empty value
      // already means "use the main model".
      $values['ai_expansion_model'] = $values['amazee_expansion_model'] ?? '';
      if (($values['amazee_model'] ?? '') !== '') {
        $values['ai_model'] = $values['amazee_model'];
      }
    }

    // Site name fallback to Drupal site name.
    if (empty($values['site_name'])) {
      $values['site_name'] = $this->configFactory->get('system.site')->get('name') ?? '';
    }

    return ScoltaConfig::fromArray($values);
  }

  /**
   * Get the explicitly configured API key, ignoring Amazee.ai credentials.
   *
   * Priority: SCOLTA_API_KEY env var > settings.php scolta.api_key — applied
   * by the shared resolver over the same candidate list resolveApiKey() uses,
   * so "which explicit key wins" is answered in one place. Passing no
   * credentials is what makes this the explicit-only accessor.
   */
  public function getApiKey(): string {
    return ApiKeyResolver::resolve($this->explicitKeyCandidates())->key;
  }

  /**
   * The explicit key candidates, in this platform's precedence order.
   *
   * @return array<string, string>
   *   Candidate keys keyed by ApiKeySource backing value.
   */
  private function explicitKeyCandidates(): array {
    $envKey = getenv('SCOLTA_API_KEY');

    return [
      'env' => $envKey === FALSE ? '' : $envKey,
      'settings' => $this->settingsApiKey(),
    ];
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
   * Resolve the effective API key, its source and its provider.
   *
   * The single derivation. Everything that reports on the API key — the
   * settings form, the health payload, Drush, and buildConfig() itself —
   * takes its answer from here rather than working it out again.
   *
   * That is the fix for scolta-php#252: buildConfig() gave an explicit
   * env/settings.php key priority over stored Amazee.ai credentials while
   * getApiKeySource() checked Amazee first, so a site running on a perfectly
   * valid SCOLTA_API_KEY was told it was connected to Amazee.ai, in success
   * green, with nothing revealing which key was really in use.
   *
   * @since 1.1.0
   * @stability experimental
   */
  public function resolveApiKey(): ResolvedApiKey {
    $config = $this->configFactory->get('scolta.settings');
    // No coalescing. Scolta ships with no provider selected, and an empty value
    // means AI is off — not that it is Anthropic. Substituting one here would
    // put the assumption back one layer down, where every reporting surface
    // reads it.
    $provider = $config->get('ai_provider') ?? '';

    return ApiKeyResolver::resolve(
      $this->explicitKeyCandidates(),
      AmazeeCredentials::fromArray(
        // Through the credential store, which decrypts. DrupalConfigStorage
        // encrypts the LiteLLM token at rest, so the raw State array holds
        // ciphertext; reading it directly here put that ciphertext in
        // ai_api_key and every message, expand and summarize call went to the
        // gateway with a bearer token it could not accept. Model resolution
        // kept working because createClient()'s self-heal already went
        // through load(), which is what made the failure look selective.
        // Null store (the minimal construction path) reads as no stored
        // credentials, the same as an absent State entry.
        // No operatorChosen: which action established the connection is now a
        // recorded fact, read from the store below rather than derived from a
        // local expression that merely correlated with it (scolta-php#273 and
        // its successor).
        $this->amazeeConfigStorage?->load(),
        connectionSource: $this->amazeeConfigStorage instanceof ProvenanceAwareConfigStorageInterface
          ? $this->amazeeConfigStorage->loadConnectionSource()
          : NULL,
      ),
      is_string($provider) ? $provider : '',
      // The managed gateway is eligible only when the operator selected it.
      // It used to be eligible for every provider except 'drupal_ai', so a
      // site that chose 'anthropic' and configured its own key was still
      // handed the stored gateway connection, and every status surface called
      // it connected to Amazee.ai. Selecting the provider is now the switch,
      // mirroring the 'drupal_ai' guard this replaces: that provider manages
      // its own provider, key and model and must never receive these
      // credentials either. Credentials are still reported as stored rather
      // than hidden, so an operator sees what exists.
      amazeeEligible: $provider === 'amazee',
    );
  }

  /**
   * Determine the source of the API key.
   *
   * @return string
   *   The backing value of the resolved source: 'env', 'settings',
   *   'constant', 'database', 'amazee', or 'none'. One Amazee case, not the
   *   'amazee:operator' / 'amazee:auto' pair it briefly had: a selected
   *   provider and a self-provisioned trial would mean different things to
   *   somebody reading a status line, but nothing records which one produced
   *   a stored token, so the distinction was invented rather than reported
   *   (scolta-php#273).
   */
  public function getApiKeySource(): string {
    return $this->resolveApiKey()->source->value;
  }

  /**
   * Whether Amazee.ai credentials are currently active.
   *
   * Derived from the shared resolution, never from the credential store:
   * credentials that lost to an explicit key are stored, not active.
   */
  public function isAmazeeActive(): bool {
    return $this->resolveApiKey()->isAmazee();
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
   * Re-resolves the gateway model names for a managed-gateway connection that
   * is ALREADY stored and whose model resolution never completed. It is not an
   * enable path: nothing here establishes a connection, and no outbound call is
   * made unless the operator selected the managed gateway and a connection is
   * stored.
   */
  protected function createClient(): AiClient {
    $resolved = $this->resolveApiKey();

    // POLICY: the only automatic gateway call left is a self-heal against
    // credentials that already exist. All three conditions are required —
    // the operator selected the managed gateway (isAmazee() is true only for
    // provider 'amazee'), a connection is stored, and the gateway-scoped model
    // is still unresolved. Nothing enrolls a site that has no connection: the
    // request path used to do exactly that whenever the key source was 'none',
    // which is how an ordinary page load could configure a gateway the
    // operator never chose.
    $unresolvedModel = !self::modelIsResolved(
      $this->configFactory->get('scolta.settings')->get('amazee_model'),
    );
    if ($resolved->isAmazee() && $resolved->amazeeCredentialsStored
      && $unresolvedModel && $this->amazeeConfigStorage !== NULL) {
      // What the heal produced, taken from the callback rather than by
      // re-reading config afterwards: the answer is what was resolved this
      // request, and reading it back would only ask the config factory to
      // repeat what it was just handed.
      $healedModel = '';
      AutoProvisioner::ensureAiAvailable(
        $this->amazeeConfigStorage,
        hasExplicitApiKey: FALSE,
        onModelsResolved: function (string $aiModel, string $aiExpansionModel) use (&$healedModel): void {
          $this->persistResolvedAmazeeModels($aiModel, $aiExpansionModel);
          $healedModel = $aiModel;
        },
        // The predicate the library uses to decide whether a re-resolution is
        // warranted. It reads the gateway-scoped key, the one
        // onModelsResolved writes: reading ai_model here would report TRUE for
        // any site whose operator had picked a model, and the self-heal would
        // never fire on exactly the sites that need it.
        hasResolvedModels: fn (): bool => self::modelIsResolved(
          $this->configFactory->get('scolta.settings')->get('amazee_model'),
        ),
      );
      // If model resolution still has not succeeded, amazee_model is empty and
      // buildConfig() leaves the shipped dated default in place — which the
      // Amazee LiteLLM gateway rejects with HTTP 400, breaking AI permanently
      // and silently. Degrade instead:
      // a key-less client makes the call throw ApiKeyMissingException, which
      // the AI controllers turn into an unexpanded/no-summary HTTP 200 (same
      // path as a wholly unconfigured site). The state self-heals on a later
      // request once /model/info recovers (hasResolvedModels reports FALSE →
      // re-resolve). Mirrors scolta-node's AmazeeAiService::buildClient().
      if (!self::modelIsResolved($healedModel)) {
        return $this->createDegradedClient();
      }
      // Re-read config in case the self-heal just persisted a model.
      return new AiClient($this->buildConfig()->toAiClientConfig(), $this->httpClient);
    }
    return new AiClient($this->getConfig()->toAiClientConfig(), $this->httpClient);
  }

  /**
   * Persist the models Amazee model resolution returned.
   *
   * The AutoProvisioner $onModelsResolved callback. The names it hands over are
   * Amazee LiteLLM **gateway aliases** (e.g. `claude-4-5-sonnet`): valid only
   * against the Amazee gateway, and rejected by Anthropic's and OpenAI's own
   * APIs. They therefore go to the gateway-scoped `amazee_model` /
   * `amazee_expansion_model`, never to the operator-facing `ai_model` /
   * `ai_expansion_model`, which hold the provider-native IDs an administrator
   * chose.
   *
   * Writing an alias into the operator-facing key is the defect this method
   * exists to prevent: it overwrote an explicit administrator choice, and the
   * moment the trial expired or a direct provider key was configured, the
   * effective provider changed while the stored alias did not — leaving AI
   * permanently degraded behind a generic ai_error (scolta-php#251).
   *
   * A method rather than a closure so it can be driven directly from a test
   * without provisioning against the live control plane.
   *
   * @since 1.1.0
   * @stability experimental
   */
  protected function persistResolvedAmazeeModels(string $aiModel, string $aiExpansionModel): void {
    $config = $this->configFactory->getEditable('scolta.settings');
    if ($aiModel !== '') {
      $config->set('amazee_model', $aiModel);
    }
    if ($aiExpansionModel !== '') {
      $config->set('amazee_expansion_model', $aiExpansionModel);
    }
    $config->save();
  }

  /**
   * Whether a genuinely resolved Amazee gateway model name is persisted.
   *
   * Always called on `scolta.settings:amazee_model`, the gateway-scoped key
   * `onModelsResolved` writes — never on the operator-facing `ai_model`.
   *
   * Reports FALSE for the unresolved state: a NULL/empty model, or the shipped
   * dated default (`AiClient::DEFAULT_MODEL`, identical to
   * `ScoltaSettingsForm::DEFAULT_AI_MODEL`), which is what a site migrated by
   * scolta_update_10003() can carry before Amazee model resolution writes a
   * real alias (e.g. `claude-sonnet-4-5`). The dated default is precisely the
   * value the Amazee gateway rejects with HTTP 400, so it must never count as
   * "resolved" — otherwise the self-heal in createClient() becomes a no-op
   * that ships the bug.
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
