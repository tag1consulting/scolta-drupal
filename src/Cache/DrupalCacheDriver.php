<?php

declare(strict_types=1);

namespace Drupal\scolta\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Tag1\Scolta\Cache\CacheDriverInterface;

/**
 * Drupal cache backend adapter for AiEndpointHandler.
 *
 * @since 0.2.0
 * @stability experimental
 */
class DrupalCacheDriver implements CacheDriverInterface {

  public function __construct(
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $key): mixed {
    $cached = $this->cache->get($key);
    return $cached ? $cached->data : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, mixed $value, int $ttlSeconds): void {
    $this->cache->set($key, $value, time() + $ttlSeconds);
  }

}
