<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Tag1\Scolta\AiClient;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Prompt\DefaultPrompts;

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
class ScoltaAiService {

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
   * The lazily-initialized AI client.
   *
   * @var \Tag1\Scolta\AiClient|null
   */
  private ?AiClient $client = NULL;

  /**
   * The cached Scolta configuration.
   *
   * @var \Tag1\Scolta\Config\ScoltaConfig|null
   */
  private ?ScoltaConfig $config = NULL;

  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    LoggerInterface $logger,
  ) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
    $this->logger = $logger;
  }

  /**
   * Get the Scolta configuration from Drupal config + settings.
   *
   * Flattens the nested scoring and display config into top-level keys
   * for ScoltaConfig::fromArray(), removes pagefind settings (not needed
   * by the AI client), and injects the API key and site name.
   */
  public function getConfig(): ScoltaConfig {
    if ($this->config === NULL) {
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

      // API key comes from env or settings.php, not exportable config.
      $values['ai_api_key'] = $this->getApiKey();

      // Site name fallback to Drupal site name.
      if (empty($values['site_name'])) {
        $values['site_name'] = $this->configFactory->get('system.site')->get('name') ?? '';
      }

      $this->config = ScoltaConfig::fromArray($values);
    }
    return $this->config;
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
   *   One of 'env', 'settings', or 'none'.
   */
  public function getApiKeySource(): string {
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
   * Check if the Drupal AI module is available.
   */
  public function hasDrupalAiModule(): bool {
    return \Drupal::hasService('ai.provider');
  }

  /**
   * Send a single-turn message via the best available AI path.
   *
   * Tries the Drupal AI module first (if available), then falls back
   * to the built-in AiClient.
   *
   * @param string $systemPrompt
   *   The system prompt.
   * @param string $userMessage
   *   The user message.
   * @param int $maxTokens
   *   Maximum response tokens.
   *
   * @return string
   *   The AI response text.
   */
  public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string {
    if ($this->hasDrupalAiModule()) {
      try {
        return $this->messageViaDrupalAi($systemPrompt, $userMessage, $maxTokens);
      }
      catch (\Exception $e) {
        $this->logger->warning('Drupal AI module message failed, falling back to built-in client: @msg', [
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    return $this->getClient()->message($systemPrompt, $userMessage, $maxTokens);
  }

  /**
   * Send a multi-turn conversation via the best available AI path.
   *
   * Tries the Drupal AI module first (if available), then falls back
   * to the built-in AiClient.
   *
   * @param string $systemPrompt
   *   The system prompt.
   * @param array $messages
   *   Array of message objects with 'role' and 'content' keys.
   * @param int $maxTokens
   *   Maximum response tokens.
   *
   * @return string
   *   The AI response text.
   */
  public function conversation(string $systemPrompt, array $messages, int $maxTokens = 512): string {
    if ($this->hasDrupalAiModule()) {
      try {
        return $this->conversationViaDrupalAi($systemPrompt, $messages, $maxTokens);
      }
      catch (\Exception $e) {
        $this->logger->warning('Drupal AI module conversation failed, falling back to built-in client: @msg', [
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    return $this->getClient()->conversation($systemPrompt, $messages, $maxTokens);
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
   * Get the AI client, configured from Drupal settings.
   */
  public function getClient(): AiClient {
    if ($this->client === NULL) {
      $config = $this->getConfig();
      $this->client = new AiClient($config->toAiClientConfig(), $this->httpClient);
    }
    return $this->client;
  }

  /**
   * Resolve a prompt template with site name and description from config.
   */
  public function resolvePrompt(string $template): string {
    $config = $this->getConfig();
    return DefaultPrompts::resolve($template, $config->siteName, $config->siteDescription);
  }

  /**
   * Get the expand-query system prompt (custom override or default).
   */
  public function getExpandPrompt(): string {
    $config = $this->getConfig();
    if (!empty($config->promptExpandQuery)) {
      return $config->promptExpandQuery;
    }
    return $this->resolvePrompt(DefaultPrompts::EXPAND_QUERY);
  }

  /**
   * Get the summarize system prompt (custom override or default).
   */
  public function getSummarizePrompt(): string {
    $config = $this->getConfig();
    if (!empty($config->promptSummarize)) {
      return $config->promptSummarize;
    }
    return $this->resolvePrompt(DefaultPrompts::SUMMARIZE);
  }

  /**
   * Get the follow-up system prompt (custom override or default).
   */
  public function getFollowUpPrompt(): string {
    $config = $this->getConfig();
    if (!empty($config->promptFollowUp)) {
      return $config->promptFollowUp;
    }
    return $this->resolvePrompt(DefaultPrompts::FOLLOW_UP);
  }

}
