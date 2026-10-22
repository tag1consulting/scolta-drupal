<?php

declare(strict_types=1);

namespace Drupal\scolta\Cache;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
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
   * @param \Drupal\Core\Cache\CacheableDependencyInterface|null $cacheability
   *   When given, every entry is written with its tags and max-age instead of
   *   the caller's TTL, and is not written at all when the max-age is 0 or
   *   any cache context is set (the key cannot vary by context). The AI
   *   controllers share one object between this driver and the prompt
   *   enricher, so what PromptEnrichEvent subscribers declare is what the
   *   response is cached with.
   */
  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly ?CacheableDependencyInterface $cacheability = NULL,
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
    if ($this->cacheability === NULL) {
      $this->cache->set($key, $value, time() + $ttlSeconds);
      return;
    }

    $maxAge = $this->cacheability->getCacheMaxAge();
    if ($maxAge === 0 || $this->cacheability->getCacheContexts() !== []) {
      return;
    }
    $expire = $maxAge === Cache::PERMANENT ? time() + $ttlSeconds : time() + $maxAge;
    $this->cache->set($key, $value, $expire, $this->cacheability->getCacheTags());
  }

}
