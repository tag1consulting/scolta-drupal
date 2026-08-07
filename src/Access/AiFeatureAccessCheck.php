<?php

declare(strict_types=1);

namespace Drupal\scolta\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Puts the AI feature rule on the route, as `_scolta_ai: expand`.
 *
 * The routes keep their `_permission: 'use scolta ai'` requirement. Drupal
 * requires every requirement on a route to allow, so the permission still
 * refuses on its own and the shipped behaviour is unchanged; this adds the
 * config flags and whatever a site has decorated onto the same endpoint the
 * block was asked about.
 *
 * @since 1.3.0
 * @stability experimental
 */
final class AiFeatureAccessCheck implements AccessInterface {

  public function __construct(
    private readonly AiAccessInterface $aiAccess,
  ) {}

  /**
   * Checks access to the AI endpoint this route serves.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route, whose `_scolta_ai` requirement names the feature.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account making the request.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(Route $route, AccountInterface $account): AccessResultInterface {
    return $this->aiAccess->access($account, (string) $route->getRequirement('_scolta_ai'));
  }

}
