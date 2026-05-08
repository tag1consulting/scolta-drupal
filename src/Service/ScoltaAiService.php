<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\AiProvider\Amazee\BudgetExceededHandler;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Tag1\Scolta\AiClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Wraps Tag1\Scolta\AiClient with Drupal config injection.
 *
 * Registered as the 'scolta.ai_service' service. Controllers and
 * commands use this instead of constructing AiClient directly.
 *
 * Supports a dual-path AI strategy:
 * 1. If the Drupal AI module (ai:ai) is installed and has a provider,
 *    route requests through its abstraction layer.
 * 2. Otherwise, fall back to the built-in AiClient with direct HTTP calls.
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

  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    LoggerInterface $logger,
    StateInterface $state,
    ?BudgetExceededHandler $budgetHandler = NULL,
  ) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
    $this->logger = $logger;
    // Assign state before parent::__construct so buildConfig() can read it.
    $this->state = $state;
    $this->budgetHandler = $budgetHandler;

    parent::__construct($this->buildConfig());
  }

  /**
   * Build ScoltaConfig from Drupal config + settings.
   *
   * Flattens the nested scoring and display config into top-level keys
   * for ScoltaConfig::fromArray(), removes pagefind settings (not needed
   * by the AI client), and injects the API key and site name.
   */
  private function buildConfig(): ScoltaConfig {
    $drupalConfig = $this->configFactory->get('scolta.settings');
    $values = $drupalConfig->getRawData();

    // Flatten nested scoring config to top-level keys.
    if (isset($values['scoring']) && is_array($values['scoring'])) {
      foreach ($values['scoring'] as $key => $value) {
        $values[$key] = $value;
      }
      unset($values['scoring']);
    }

    // Flatten nested display config to top-level keys.
    if (isset($values['display']) && is_array($values['display'])) {
      foreach ($values['display'] as $key => $value) {
        $values[$key] = $value;
      }
      unset($values['display']);
    }

    // Remove pagefind config (not relevant to ScoltaConfig).
    unset($values['pagefind']);

    // Amazee.ai credentials take precedence over env/settings API keys.
    $amazeeCreds = $this->state->get('scolta.amazee.credentials');
    if (is_array($amazeeCreds) && !empty($amazeeCreds['litellm_token'])) {
      $values['ai_provider'] = 'openai';
      $values['ai_api_key'] = $amazeeCreds['litellm_token'];
      $values['ai_base_url'] = $amazeeCreds['litellm_api_url'] ?? '';
    }
    else {
      // API key comes from env or settings.php, not exportable config.
      $values['ai_api_key'] = $this->getApiKey();
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

    return Settings::get('scolta.api_key', '');
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

    $settingsKey = Settings::get('scolta.api_key', '');
    if (!empty($settingsKey)) {
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
   */
  public function hasDrupalAiModule(): bool {
    return \Drupal::hasService('ai.provider');
  }

  /**
   * {@inheritdoc}
   */
  protected function tryFrameworkAi(string $systemPrompt, string $userMessage, int $maxTokens): ?string {
    if (!$this->hasDrupalAiModule()) {
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
   */
  protected function tryFrameworkConversation(string $systemPrompt, array $messages, int $maxTokens): ?string {
    if (!$this->hasDrupalAiModule()) {
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
   * Send a single message via the Drupal AI module.
   */
  protected function messageViaDrupalAi(string $systemPrompt, string $userMessage, int $maxTokens): string {
    /** @var \Drupal\ai\AiProviderPluginManager $aiProvider */
    $aiProvider = \Drupal::service('ai.provider');

    $config = $this->getConfig();

    $input = new ChatInput([
      new ChatMessage('system', $systemPrompt),
      new ChatMessage('user', $userMessage),
    ]);

    $provider = $aiProvider->createInstance($config->aiProvider);
    $response = $provider->chat($input, $config->aiModel, [
      'max_tokens' => $maxTokens,
    ]);

    return $response->getNormalized()->getText();
  }

  /**
   * Send a multi-turn conversation via the Drupal AI module.
   */
  protected function conversationViaDrupalAi(string $systemPrompt, array $messages, int $maxTokens): string {
    /** @var \Drupal\ai\AiProviderPluginManager $aiProvider */
    $aiProvider = \Drupal::service('ai.provider');

    $config = $this->getConfig();

    $chatMessages = [
      new ChatMessage('system', $systemPrompt),
    ];
    foreach ($messages as $msg) {
      $chatMessages[] = new ChatMessage($msg['role'], $msg['content']);
    }

    $input = new ChatInput($chatMessages);

    $provider = $aiProvider->createInstance($config->aiProvider);
    $response = $provider->chat($input, $config->aiModel, [
      'max_tokens' => $maxTokens,
    ]);

    return $response->getNormalized()->getText();
  }

  /**
   * {@inheritdoc}
   *
   * Intercepts AmazeeBudgetExceededException to show an admin notice before
   * re-throwing, so callers still get a clean exception.
   */
  public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string {
    try {
      return parent::message($systemPrompt, $userMessage, $maxTokens);
    }
    catch (\RuntimeException $e) {
      $this->handlePossibleBudgetException($e);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function conversation(string $systemPrompt, array $messages, int $maxTokens = 512): string {
    try {
      return parent::conversation($systemPrompt, $messages, $maxTokens);
    }
    catch (\RuntimeException $e) {
      $this->handlePossibleBudgetException($e);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function messageForOperation(string $operation, string $systemPrompt, string $userMessage, int $maxTokens = 512): string {
    try {
      return parent::messageForOperation($operation, $systemPrompt, $userMessage, $maxTokens);
    }
    catch (\RuntimeException $e) {
      $this->handlePossibleBudgetException($e);
      throw $e;
    }
  }

  /**
   * Converts a budget-exceeded RuntimeException to AmazeeBudgetExceededException.
   *
   * Notifies the handler and re-throws. No-op if the message does not match.
   */
  private function handlePossibleBudgetException(\RuntimeException $e): void {
    if (!str_contains($e->getMessage(), 'Budget has been exceeded!')) {
      return;
    }
    $budgetException = new AmazeeBudgetExceededException($e);
    $this->budgetHandler?->handle($budgetException);
    throw $budgetException;
  }

  /**
   * {@inheritdoc}
   */
  protected function createClient(): AiClient {
    return new AiClient($this->getConfig()->toAiClientConfig(), $this->httpClient);
  }

}
