<?php

declare(strict_types=1);

namespace Drupal\scolta\Prompt;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\scolta\Event\PromptEnrichEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tag1\Scolta\Prompt\PromptEnricherInterface;

/**
 * Enriches prompts by dispatching a PromptEnrichEvent.
 *
 * Bridges scolta-php's PromptEnricherInterface to Drupal's event system so
 * any module can subscribe to PromptEnrichEvent and inject site-specific
 * context into AI prompts.
 *
 * When built with a CacheableMetadata, each enrich() seeds the event from it
 * and writes the refined result back into the same object, so a cache driver
 * holding that object caches the response with the cacheability the
 * subscribers declared. See PromptEnrichEvent for the contract.
 *
 * @since 1.0.0-rc1
 * @stability experimental
 */
class EventDrivenEnricher implements PromptEnricherInterface {

  public function __construct(
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ?CacheableMetadata $cacheability = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enrich(string $resolvedPrompt, string $promptName, array $context = []): string {
    $event = new PromptEnrichEvent($resolvedPrompt, $promptName, $context, $this->cacheability);
    $this->eventDispatcher->dispatch($event);

    if ($this->cacheability !== NULL) {
      $this->cacheability
        ->setCacheTags($event->getCacheTags())
        ->setCacheContexts($event->getCacheContexts())
        ->setCacheMaxAge($event->getCacheMaxAge());
    }

    return $event->getResolvedPrompt();
  }

}
