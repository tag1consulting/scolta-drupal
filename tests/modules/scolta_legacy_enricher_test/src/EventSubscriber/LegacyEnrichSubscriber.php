<?php

declare(strict_types=1);

namespace Drupal\scolta_legacy_enricher_test\EventSubscriber;

// The pre-split namespace, exactly as an enricher written for scolta 1.x has
// it. Nothing else in this module may use the new one.
use Drupal\scolta\Event\PromptEnrichEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Appends a marker to every prompt, subscribed under the old event name.
 */
class LegacyEnrichSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PromptEnrichEvent::class => 'onPromptEnrich'];
  }

  /**
   * Appends the marker.
   */
  public function onPromptEnrich(PromptEnrichEvent $event): void {
    $event->setResolvedPrompt($event->getResolvedPrompt() . ' [legacy]');
  }

}
