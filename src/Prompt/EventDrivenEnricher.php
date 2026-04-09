<?php

declare(strict_types=1);

namespace Drupal\scolta\Prompt;

use Drupal\scolta\Event\PromptEnrichEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tag1\Scolta\Prompt\PromptEnricherInterface;

/**
 * Prompt enricher that dispatches a Symfony event for Drupal subscribers.
 *
 * This bridges the scolta-php PromptEnricherInterface with Drupal's event
 * system. Modules can subscribe to PromptEnrichEvent to inject site-specific
 * context into AI prompts.
 *
 * @since 0.2.0
 * @stability experimental
 */
class EventDrivenEnricher implements PromptEnricherInterface {

  public function __construct(
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enrich(string $resolvedPrompt, string $promptName, array $context = []): string {
    $event = new PromptEnrichEvent($resolvedPrompt, $promptName, $context);
    $this->eventDispatcher->dispatch($event);

    return $event->getResolvedPrompt();
  }

}
