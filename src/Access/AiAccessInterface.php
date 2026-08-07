<?php

declare(strict_types=1);

namespace Drupal\scolta\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Answers whether one account may use one AI feature on this request.
 *
 * The three AI endpoints make cost-bearing LLM calls, so who reaches them is
 * a decision a site has to be able to take part in. Before this service the
 * only such decision was the 'use scolta ai' permission, enforced as a route
 * requirement and nowhere else — so a site with a narrower rule than "this
 * role may" (a per-user preference, a quota, an entitlement carried on a
 * subscription) had nowhere to put it, and the search block advertised the
 * features to visitors the routes would refuse.
 *
 * Implementations answer for the whole request path: ScoltaSearchBlock asks
 * before it tells the browser a feature exists, and AiFeatureAccessCheck asks
 * again on the endpoint the browser then calls. One answer, both gates.
 *
 * This is about **who**, not about **what the site offers**. The
 * ai_expand_query / ai_summarize flags and the max_follow_ups quota stay in
 * config and stay enforced by AiEndpointHandler, which answers 404 for a
 * switched-off feature and 429 for an exhausted follow-up quota; an
 * implementation must not restate them, or those documented responses become
 * a 403 from routing. The block combines both itself.
 *
 * Decorate the scolta.ai_access service to narrow it. Return the inner
 * result unchanged when it already refuses, and remember that the answer is
 * cached with the block: an implementation that varies by account must say
 * so on the AccessResult it returns, or one visitor's answer is served to
 * the next. Declare the variation with a cache context keyed on the value
 * the rule reads — a boolean preference then costs two shared cache variants
 * where cachePerUser() would cost one per account. README.md has the full
 * recipe, including the folded-case invalidation the context must carry.
 *
 * @since 1.3.0
 * @stability experimental
 */
interface AiAccessInterface {

  /**
   * Query expansion: POST /api/scolta/v1/expand-query.
   */
  public const FEATURE_EXPAND = 'expand';

  /**
   * The AI overview of the results: POST /api/scolta/v1/summarize.
   */
  public const FEATURE_SUMMARIZE = 'summarize';

  /**
   * Follow-up questions about the overview: POST /api/scolta/v1/followup.
   */
  public const FEATURE_FOLLOW_UP = 'follow_up';

  /**
   * Every feature this service answers for.
   *
   * The route requirements name these strings, so an implementation can tell
   * a feature it has no opinion about from one it has never heard of.
   */
  public const FEATURES = [
    self::FEATURE_EXPAND,
    self::FEATURE_SUMMARIZE,
    self::FEATURE_FOLLOW_UP,
  ];

  /**
   * Checks access to one AI feature.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check, which is the current user on both call paths.
   * @param string $feature
   *   One of the FEATURE_* constants. An unrecognised value is refused
   *   rather than allowed, so a typo cannot open an endpoint.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result, carrying the cacheability of whatever it consulted.
   */
  public function access(AccountInterface $account, string $feature): AccessResultInterface;

}
