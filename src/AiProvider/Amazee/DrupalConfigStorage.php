<?php

declare(strict_types=1);

namespace Drupal\scolta\AiProvider\Amazee;

use Drupal\Core\State\StateInterface;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

/**
 * Stores Amazee.ai credentials in Drupal State.
 *
 * State is used rather than Config Management (CMI) because LiteLLM tokens
 * are secrets that must not be exported to config sync or version control.
 *
 * @since 0.4.0
 * @stability experimental
 */
final class DrupalConfigStorage implements ConfigStorageInterface {

  private const STATE_KEY = 'scolta.amazee.credentials';

  public function __construct(private readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function store(string $litellmToken, string $litellmApiUrl, string $region): void {
    $this->state->set(self::STATE_KEY, [
      'litellm_token' => $litellmToken,
      'litellm_api_url' => $litellmApiUrl,
      'region' => $region,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function load(): ?array {
    $data = $this->state->get(self::STATE_KEY);
    if (!is_array($data) || empty($data['litellm_token'])) {
      return NULL;
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): void {
    $this->state->delete(self::STATE_KEY);
  }

}
