<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta_ui\Event\PromptEnrichEvent;
use Drupal\scolta_ui\Prompt\EventDrivenEnricher;

/**
 * A prompt enricher written before the split keeps enriching prompts.
 *
 * The split moved PromptEnrichEvent from scolta to scolta_ui. A subscriber
 * keys on the class name as a string, so one still on the old namespace would
 * never hear the event under its new name, and would fail without a sound:
 * its rules would just stop reaching the prompt. scolta_legacy_enricher_test
 * is such a subscriber, written against the old namespace throughout.
 *
 * @group scolta
 * @group legacy
 */
class LegacyPromptEnrichEventKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta_ui', 'scolta_legacy_enricher_test'];

  private const DEPRECATION = 'Subscribing to Drupal\scolta\Event\PromptEnrichEvent is deprecated in scolta:2.0.0 and is removed from scolta:3.0.0. Subscribe to Drupal\scolta_ui\Event\PromptEnrichEvent instead. See https://github.com/tag1consulting/scolta-drupal/issues/168';

  /**
   * The old subscriber still edits the prompt, and is told to move.
   */
  public function testLegacySubscriberStillEnrichesThePrompt(): void {
    $this->expectDeprecation(self::DEPRECATION);

    $this->assertSame('Base prompt. [legacy]', $this->enrich('Base prompt.'));
  }

  /**
   * The old name is an alias of the new class, so old type hints resolve.
   */
  public function testOldClassNameResolvesToTheNewClass(): void {
    $this->assertTrue(class_exists('Drupal\scolta\Event\PromptEnrichEvent'));
    $this->assertInstanceOf('Drupal\scolta\Event\PromptEnrichEvent', new PromptEnrichEvent('p', 'summarize'));
  }

  /**
   * Old subscribers run after current ones and see what those wrote.
   *
   * Even a current subscriber at the lowest priority: the two names are two
   * dispatches, and the old one comes second.
   */
  public function testLegacySubscribersRunAfterCurrentOnes(): void {
    $this->expectDeprecation(self::DEPRECATION);

    $this->container->get('event_dispatcher')->addListener(
      PromptEnrichEvent::class,
      fn (PromptEnrichEvent $event) => $event->setResolvedPrompt($event->getResolvedPrompt() . ' [current]'),
      -1000,
    );

    $this->assertSame('Base prompt. [current] [legacy]', $this->enrich('Base prompt.'));
  }

  /**
   * A current subscriber that stops propagation stops old ones too.
   */
  public function testStoppedPropagationReachesLegacySubscribers(): void {
    $this->expectDeprecation(self::DEPRECATION);

    $this->container->get('event_dispatcher')->addListener(
      PromptEnrichEvent::class,
      fn (PromptEnrichEvent $event) => $event->stopPropagation(),
    );

    $this->assertSame('Base prompt.', $this->enrich('Base prompt.'));
  }

  /**
   * With no old subscriber, nothing is dispatched under the old name.
   */
  public function testNoLegacyDispatchWithoutLegacySubscribers(): void {
    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->removeSubscriber($this->container->get('scolta_legacy_enricher_test.subscriber'));

    $this->assertFalse($dispatcher->hasListeners(EventDrivenEnricher::LEGACY_EVENT_NAME));
    $this->assertSame('Base prompt.', $this->enrich('Base prompt.'));
  }

  /**
   * Runs the enricher the AI controllers use, on the real dispatcher.
   */
  private function enrich(string $prompt): string {
    return (new EventDrivenEnricher($this->container->get('event_dispatcher')))
      ->enrich($prompt, 'summarize');
  }

}
