<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * The shipped rule: an account may use the AI features it has permission for.
 *
 * This is the existing `use scolta ai` check and nothing else. It answers the
 * same for every feature, because the permission does; the feature argument
 * exists for decorators, which routinely need to tell expansion from an
 * overview.
 *
 * **Config flags are deliberately not read here.** `ai_expand_query`,
 * `ai_summarize` and `max_follow_ups` say what the site offers, not who may
 * ask for it, and AiEndpointHandler already enforces all three with the
 * status codes callers are written against: 404 for a switched-off feature,
 * 429 with the remaining `limit` for the follow-up quota. Folding them in
 * here would refuse those requests at routing instead, turning a documented
 * response into a bare 403 on sites that have decorated nothing — and the
 * follow-up quota could not be expressed correctly in any case, since the
 * real rule counts messages in the request body, which no access check sees.
 * ScoltaSearchBlock combines the two itself: config decides whether a feature
 * is offered at all, this service decides whether the visitor gets it.
 *
 * @since 1.3.0
 * @stability experimental
 */
final class AiAccess implements AiAccessInterface {

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account, string $feature): AccessResultInterface {
    // An unrecognised feature is refused rather than allowed, so a typo in a
    // route requirement cannot open an endpoint.
    if (!in_array($feature, self::FEATURES, TRUE)) {
      return AccessResult::forbidden(sprintf('"%s" is not a Scolta AI feature.', $feature));
    }

    return AccessResult::allowedIfHasPermission($account, 'use scolta ai');
  }

}
