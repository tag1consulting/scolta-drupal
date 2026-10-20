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
 * The final prompt text is part of the AI response cache key (scolta-php
 * hashes the resolved system prompt and the model alongside the query), so
 * a subscriber's contribution is already accounted for: when the text it
 * adds changes, the next request lands on a fresh key, and an unchanged
 * prompt serves the cached response. Nothing further has to be declared for
 * that, and most subscribers need not touch cacheability at all.
 *
 * The event is nonetheless the cacheability of the response about to be
 * cached, seeded with the module's own values (the config tags the prompt
 * is built from and the configured AI cache TTL as max-age), and whatever
 * it holds once every subscriber has run is what the entry is written with.
 * The unusual cases where that matters:
 *
 * - A response that must not be reused at all (a prompt carrying a per-user
 *   or time-sensitive instruction): mergeCacheMaxAge(0) keeps it out of the
 *   cache instead of writing an entry per variation.
 * - A response that should live shorter than the configured TTL:
 *   mergeCacheMaxAge($seconds).
 * - A tag, when the stale entry should disappear at once rather than sit
 *   unread until its TTL: addCacheableDependency($node) retires it when the
 *   node is saved. The new key would already have been served; this only
 *   reclaims the old entry's space.
 *
 * Merging only ever tightens: tags accumulate and mergeCacheMaxAge() takes
 * the minimum, so the seeded config tags cannot be lost by accident, while
 * setCacheMaxAge() is there for a subscriber that means to override.
 *
 * Cache contexts have nothing to express here, since any variation in the
 * prompt text is already in the key. A response whose event carries one is
 * not cached, the only reading of "varies by X" that is never wrong.
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
