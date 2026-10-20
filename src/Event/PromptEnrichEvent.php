<?php

declare(strict_types=1);

namespace Drupal\scolta\Event;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before an AI prompt is sent to the LLM provider.
 *
 * Subscribe to this event to inject site-specific context into prompts.
 * For example, a subscriber could append product catalog information,
 * compliance rules, or tenant-specific instructions.
 *
 * The event is also the cacheability of the AI response that is about to be
 * generated and cached. It arrives seeded with the module's own values (the
 * config tags the prompt is built from and the configured AI cache TTL as
 * max-age), and whatever the response is cached with afterwards is exactly
 * what the event holds once every subscriber has run. A subscriber that
 * folds content into the prompt declares it the way the rest of Drupal does:
 *
 * @code
 * $event->setResolvedPrompt($prompt . "\n" . $node->body->value);
 * $event->addCacheableDependency($node);
 * @endcode
 *
 * and the cached expansion or summary is retired when that node is saved.
 * Merging only ever tightens: tags accumulate and mergeCacheMaxAge() takes
 * the minimum, so the seeded config tags cannot be lost by accident, while
 * setCacheMaxAge() is there for a subscriber that means to override.
 * mergeCacheMaxAge(0) keeps the response out of the cache altogether.
 *
 * Cache contexts cannot be honoured: the cache key is built by scolta-php
 * from the query and the generation counter, so nothing can vary it per
 * context. A response whose event carries any cache context is therefore
 * not cached at all, the only reading of "varies by X" that is never wrong.
 *
 * The lookup that precedes this event does not consult subscribers: an
 * entry that is already cached is served as is, which is why the
 * declaration has to travel with the entry when it is written.
 *
 * @since 1.0.0-rc1
 * @stability experimental
 */
class PromptEnrichEvent extends Event implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  /**
   * Constructs a PromptEnrichEvent.
   *
   * @param string $resolvedPrompt
   *   The prompt text after template resolution.
   * @param string $promptName
   *   The prompt identifier ('expand_query', 'summarize', or 'follow_up').
   * @param array $context
   *   Additional context (e.g., query, search results, messages).
   * @param \Drupal\Core\Cache\CacheableDependencyInterface|null $cacheability
   *   The cacheability the response starts from, before subscribers refine
   *   it. NULL leaves the event at its defaults (no tags, permanent).
   */
  public function __construct(
    private string $resolvedPrompt,
    private readonly string $promptName,
    private readonly array $context = [],
    ?CacheableDependencyInterface $cacheability = NULL,
  ) {
    if ($cacheability !== NULL) {
      $this->addCacheableDependency($cacheability);
    }
  }

  /**
   * Replace the max-age outright, where the merge would only shorten it.
   *
   * The merge takes the minimum, which is the right default and all most
   * subscribers need (mergeCacheMaxAge(0) keeps the response out of the
   * cache). This is for the subscriber that means to keep a response longer
   * than the configured TTL.
   *
   * @param int $maxAge
   *   Seconds, 0 for uncacheable, or \Drupal\Core\Cache\Cache::PERMANENT.
   *
   * @return $this
   */
  public function setCacheMaxAge(int $maxAge): static {
    $this->cacheMaxAge = $maxAge;
    return $this;
  }

  /**
   * Get the current prompt text.
   */
  public function getResolvedPrompt(): string {
    return $this->resolvedPrompt;
  }

  /**
   * Set the modified prompt text.
   */
  public function setResolvedPrompt(string $resolvedPrompt): void {
    $this->resolvedPrompt = $resolvedPrompt;
  }

  /**
   * Get the prompt name identifier.
   */
  public function getPromptName(): string {
    return $this->promptName;
  }

  /**
   * Get the additional context.
   */
  public function getContext(): array {
    return $this->context;
  }

}
