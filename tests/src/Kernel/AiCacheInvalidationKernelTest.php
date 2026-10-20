<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Cache\DrupalCacheDriver;
use Drupal\scolta\Controller\AiApiControllerBase;
use Drupal\scolta\Event\PromptEnrichEvent;
use Drupal\scolta\Prompt\EventDrivenEnricher;

/**
 * What retires a cached AI response, and what leaves it alone.
 *
 * The AI controllers seed one CacheableMetadata per request from the config
 * their system prompt is built from and the configured TTL, hand it to both
 * the prompt enricher and the cache driver, and let PromptEnrichEvent
 * subscribers refine it. Each test here runs that pipeline against the real
 * cache.default bin: enrich, then write, then see what retires the entry.
 *
 * @group scolta
 */
class AiCacheInvalidationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'scolta']);
  }

  /**
   * Enrich a prompt as a request does, then return the driver to write with.
   *
   * @param callable|null $subscriber
   *   A PromptEnrichEvent listener standing in for a site's subscriber.
   */
  protected function enrichedDriver(?callable $subscriber = NULL): DrupalCacheDriver {
    if ($subscriber !== NULL) {
      $this->container->get('event_dispatcher')->addListener(PromptEnrichEvent::class, $subscriber);
    }
    // What AiApiControllerBase::handle() seeds.
    $cacheability = (new CacheableMetadata())
      ->setCacheTags(AiApiControllerBase::CACHE_TAGS)
      ->setCacheMaxAge(3600);
    (new EventDrivenEnricher($this->container->get('event_dispatcher'), $cacheability))
      ->enrich('System prompt.', 'expand_query', ['query' => 'math']);
    return new DrupalCacheDriver($this->container->get('cache.default'), $cacheability);
  }

  public function testSettingsSaveRetiresCachedAiResponses(): void {
    $driver = $this->enrichedDriver();
    $driver->set('scolta_expand_0_abc', ['terms' => ['math']], 3600);
    $this->assertSame(['terms' => ['math']], $driver->get('scolta_expand_0_abc'));

    $this->config('scolta.settings')->set('ai_model', 'claude-haiku-5-5')->save();

    $this->assertNull($driver->get('scolta_expand_0_abc'), 'A scolta.settings save must retire cached AI responses: the prompt they were generated with may have changed');
  }

  public function testSiteNameSaveRetiresCachedAiResponses(): void {
    $driver = $this->enrichedDriver();
    $driver->set('scolta_summarize_0_abc', ['summary' => 'A summary.'], 3600);

    $this->config('system.site')->set('name', 'Renamed site')->save();

    $this->assertNull($driver->get('scolta_summarize_0_abc'), 'The default prompts embed the site name, so a system.site save must retire cached AI responses');
  }

  public function testIndexInvalidationLeavesCachedAiResponsesAlone(): void {
    $driver = $this->enrichedDriver();
    $driver->set('scolta_expand_0_abc', ['terms' => ['math']], 3600);

    // What every build, full or incremental, emits for the search block.
    $this->container->get('cache_tags.invalidator')->invalidateTags(['scolta_search_index']);
    // An unrelated config save.
    $this->config('system.date')->set('first_day', 1)->save();

    $this->assertSame(['terms' => ['math']], $driver->get('scolta_expand_0_abc'), 'Neither an index change nor unrelated config may retire a cached AI response');
  }

  /**
   * A subscriber's cache tags travel with the entry, beside the seeded ones.
   */
  public function testSubscriberTagsRetireTheResponse(): void {
    $driver = $this->enrichedDriver(function (PromptEnrichEvent $event): void {
      $event->setResolvedPrompt($event->getResolvedPrompt() . ' Node 1 says hello.');
      $event->addCacheTags(['node:1']);
    });
    $driver->set('scolta_expand_0_abc', ['terms' => ['math']], 3600);
    $this->assertSame(['terms' => ['math']], $driver->get('scolta_expand_0_abc'));

    $this->container->get('cache_tags.invalidator')->invalidateTags(['node:1']);
    $this->assertNull($driver->get('scolta_expand_0_abc'), 'Saving content a subscriber folded into the prompt must retire the response');

    // The seeded config tags are still there: a tag merge only accumulates.
    $driver->set('scolta_expand_0_def', ['terms' => ['math']], 3600);
    $this->config('scolta.settings')->set('ai_model', 'claude-haiku-5-5')->save();
    $this->assertNull($driver->get('scolta_expand_0_def'));
  }

  /**
   * Max-age 0 from a subscriber keeps the response out of the cache.
   */
  public function testSubscriberMaxAgeZeroPreventsCaching(): void {
    $driver = $this->enrichedDriver(function (PromptEnrichEvent $event): void {
      $event->mergeCacheMaxAge(0);
    });
    $driver->set('scolta_expand_0_abc', ['terms' => ['math']], 3600);

    $this->assertNull($driver->get('scolta_expand_0_abc'), 'A response whose prompt declared max-age 0 must not be cached');
  }

  /**
   * A shorter max-age from a subscriber wins over the configured TTL.
   */
  public function testSubscriberMaxAgeShortensTheEntry(): void {
    $driver = $this->enrichedDriver(function (PromptEnrichEvent $event): void {
      $event->mergeCacheMaxAge(60);
    });
    $driver->set('scolta_expand_0_abc', ['terms' => ['math']], 3600);

    $item = $this->container->get('cache.default')->get('scolta_expand_0_abc');
    $this->assertNotFalse($item);
    $this->assertEqualsWithDelta(time() + 60, $item->expire, 5, 'The entry must expire at the subscriber\'s max-age, not the configured TTL');
  }

  /**
   * A cache context cannot vary the key, so it makes the response uncacheable.
   */
  public function testSubscriberCacheContextPreventsCaching(): void {
    $driver = $this->enrichedDriver(function (PromptEnrichEvent $event): void {
      $event->addCacheContexts(['user.roles']);
    });
    $driver->set('scolta_expand_0_abc', ['terms' => ['math']], 3600);

    $this->assertNull($driver->get('scolta_expand_0_abc'), 'A response that varies by a cache context cannot be keyed for it and must not be cached');
  }

}
