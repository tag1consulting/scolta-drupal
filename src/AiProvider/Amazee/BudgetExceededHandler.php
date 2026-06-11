<?php

declare(strict_types=1);

namespace Drupal\scolta\AiProvider\Amazee;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;

/**
 * Shows an admin warning when the Amazee.ai budget is exhausted.
 *
 * Rate-limited to once per 24 hours via State so noisy search traffic
 * does not spam every admin page load with the same notice.
 *
 * @since 1.0.0-rc1
 * @stability experimental
 */
final class BudgetExceededHandler {

  use StringTranslationTrait;

  private const THROTTLE_SECONDS = 86400;
  private const STATE_KEY = 'scolta.amazee.budget_notice_time';

  public function __construct(
    private readonly MessengerInterface $messenger,
    private readonly StateInterface $state,
  ) {}

  /**
   * Record the budget-exceeded event and show an admin notice if not throttled.
   *
   * Callers (e.g. ScoltaAiService) catch AmazeeBudgetExceededException, pass it
   * here, then re-throw it so the caller's exception handling still applies.
   */
  public function handle(AmazeeBudgetExceededException $e): void {
    $lastTime = (int) $this->state->get(self::STATE_KEY, 0);
    if ((time() - $lastTime) < self::THROTTLE_SECONDS) {
      return;
    }

    $this->state->set(self::STATE_KEY, time());

    $this->messenger->addWarning($this->t(
      'Your Amazee.ai AI budget has been exceeded. Visit the <a href=":url">Amazee.ai settings</a> to upgrade your plan.',
      [':url' => Url::fromRoute('scolta.settings.amazee')->toString()],
    ));
  }

}
