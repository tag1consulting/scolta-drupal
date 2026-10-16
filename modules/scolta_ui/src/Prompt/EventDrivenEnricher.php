<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Prompt;

use Drupal\scolta_ui\Event\PromptEnrichEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as ComponentEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tag1\Scolta\Prompt\PromptEnricherInterface;

/**
 * Prompt enricher that dispatches a Symfony event for Drupal subscribers.
 *
 * This bridges the scolta-php PromptEnricherInterface with Drupal's event
 * system. Modules can subscribe to PromptEnrichEvent to inject site-specific
 * context into AI prompts.
 *
 * @since 1.0.0-rc1
 * @stability experimental
 */
class EventDrivenEnricher implements PromptEnricherInterface {

  /**
   * The event's name before the split moved it into scolta_ui.
   *
   * Subscribers key on the class name as a string, and scolta_ui.module's
   * class_alias() cannot change a string a subscriber already wrote, so an
   * enricher written against the old namespace would never hear the event
   * under its new name. It would not fail, either: its rules would simply
   * stop reaching the prompt.
   *
   * Subscribing to it is deprecated in scolta:2.0.0 and is removed from
   * scolta:3.0.0, along with this constant.
   *
   * @todo Remove in scolta:3.0.0.
   *
   * @see https://github.com/tag1consulting/scolta-drupal/issues/168
   */
  public const LEGACY_EVENT_NAME = 'Drupal\\scolta\\Event\\PromptEnrichEvent';

  public function __construct(
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enrich(string $resolvedPrompt, string $promptName, array $context = []): string {
    $event = new PromptEnrichEvent($resolvedPrompt, $promptName, $context);
    $this->eventDispatcher->dispatch($event);

    // Subscribers still on the old name hear the same event object after the
    // current ones, so their edits build on what the others wrote. A
    // subscriber that stopped propagation stops them too: the dispatcher
    // checks before every listener.
    if ($this->eventDispatcher instanceof ComponentEventDispatcherInterface
      && $this->eventDispatcher->hasListeners(self::LEGACY_EVENT_NAME)) {
      // The project's issues are on GitHub, not drupal.org.
      // phpcs:ignore Drupal.Semantics.FunctionTriggerError.TriggerErrorSeeUrlFormat
      @trigger_error('Subscribing to Drupal\\scolta\\Event\\PromptEnrichEvent is deprecated in scolta:2.0.0 and is removed from scolta:3.0.0. Subscribe to Drupal\\scolta_ui\\Event\\PromptEnrichEvent instead. See https://github.com/tag1consulting/scolta-drupal/issues/168', E_USER_DEPRECATED);
      $this->eventDispatcher->dispatch($event, self::LEGACY_EVENT_NAME);
    }

    return $event->getResolvedPrompt();
  }

}
