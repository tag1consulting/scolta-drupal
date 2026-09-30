<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Tag1\Scolta\Chat\ThreadStoreInterface;

/**
 * Chat threads in the expirable key value store, collection scolta_chat.
 *
 * Expired threads are removed by system cron like every expirable entry.
 * Keys arrive from scolta-php's ChatOwner already hashed per owner.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class ScoltaChatThreadStore implements ThreadStoreInterface {

  /**
   * The scolta_chat collection.
   */
  private KeyValueStoreExpirableInterface $store;

  public function __construct(KeyValueExpirableFactoryInterface $factory) {
    $this->store = $factory->get('scolta_chat');
  }

  /**
   * {@inheritdoc}
   */
  public function load(string $key): ?array {
    $data = $this->store->get($key);
    return is_array($data) ? $data : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function save(string $key, array $data, int $ttl): void {
    $this->store->setWithExpire($key, $data, $ttl);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $key): void {
    $this->store->delete($key);
  }

}
