<?php

declare(strict_types=1);

namespace Drupal\scolta\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Tag1\Scolta\Cache\CacheDriverInterface;

/**
 * Drupal cache backend adapter for AiEndpointHandler.
 *
 * @since 1.0.0-rc1
 * @stability experimental
 */
class DrupalCacheDriver implements CacheDriverInterface {

  /**
   * Constructs the driver.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The bin to read and write.
   * @param string[] $tags
   *   Cache tags applied to every entry written through this driver, so
   *   Drupal's own tag invalidation (a config save, a cache rebuild) retires
   *   them without any Scolta-specific bookkeeping.
   */
  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly array $tags = [],
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
    $this->cache->set($key, $value, time() + $ttlSeconds, $this->tags);
  }

}
