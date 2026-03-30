<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Tag1\Scolta\AiClient;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Prompt\DefaultPrompts;

/**
 * Wraps Tag1\Scolta\AiClient with Drupal config injection.
 *
 * Registered as the 'scolta.ai_service' service. Controllers and
 * commands use this instead of constructing AiClient directly.
 */
class ScoltaAiService {

  private ConfigFactoryInterface $configFactory;
  private ClientInterface $httpClient;
  private ?AiClient $client = null;
  private ?ScoltaConfig $config = null;

  public function __construct(ClientInterface $httpClient, ConfigFactoryInterface $configFactory) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
  }

  /**
   * Get the Scolta configuration from Drupal config + settings.
   */
  public function getConfig(): ScoltaConfig {
    if ($this->config === null) {
      $drupalConfig = $this->configFactory->get('scolta.settings');
      $values = $drupalConfig->getRawData();

      // API key comes from Drupal settings.php, not config (not exportable).
      $apiKey = \Drupal\Core\Site\Settings::get('scolta.api_key', '');
      $values['ai_api_key'] = $apiKey;

      $this->config = ScoltaConfig::fromArray($values);
    }
    return $this->config;
  }

  /**
   * Get the AI client, configured from Drupal settings.
   */
  public function getClient(): AiClient {
    if ($this->client === null) {
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
