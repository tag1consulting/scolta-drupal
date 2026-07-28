<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Executes the update hook that backfills the anonymous AI permission grant.
 *
 * scolta_install() grants 'use scolta ai' to anonymous and authenticated, so
 * a fresh install serves AI overviews to visitors out of the box. A site that
 * was installed before that grant existed keeps Drupal's restrictive default
 * and answers 403 to every anonymous /api/scolta/v1/* request, no matter what
 * release it updates to, until scolta_update_10001() runs.
 *
 * Each test recreates that pre-fix state by revoking the permission from a
 * freshly installed site, which is the same config state an old site is in.
 *
 * @group scolta
 */
class AiPermissionBackfillFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The AI endpoints gated by 'use scolta ai'.
   */
  private const AI_ENDPOINTS = [
    '/api/scolta/v1/expand-query',
    '/api/scolta/v1/summarize',
    '/api/scolta/v1/followup',
  ];

  /**
   * A fresh install grants the permission without any update hook.
   *
   * The regression guard on the install path: the update hook backfills
   * existing sites, it does not take over from scolta_install().
   */
  public function testFreshInstallGrantsThePermission(): void {
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $role_id) {
      $this->assertTrue(
        $this->roleHasAiPermission($role_id),
        "A fresh install must grant 'use scolta ai' to {$role_id}"
      );
    }
  }

  /**
   * The update grants the permission to a site that lacks it.
   */
  public function testUpdateBackfillsBothRoles(): void {
    $this->simulatePreFixSite();

    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $role_id) {
      $this->assertFalse(
        $this->roleHasAiPermission($role_id),
        "Precondition: {$role_id} must start without the permission"
      );
    }

    $this->runBackfillUpdate();

    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $role_id) {
      $this->assertTrue(
        $this->roleHasAiPermission($role_id),
        "The update must grant 'use scolta ai' to {$role_id}"
      );
    }
  }

  /**
   * Anonymous visitors reach the AI endpoints once the update has run.
   *
   * This is the user-visible symptom from the issue: 403 before, not-403
   * after. The endpoints reject an empty body with a 4xx of their own, so the
   * assertion is specifically about 403 rather than about success.
   */
  public function testAnonymousReachesAiEndpointsAfterUpdate(): void {
    $this->simulatePreFixSite();

    foreach (self::AI_ENDPOINTS as $endpoint) {
      $this->assertSame(
        403, $this->postStatus($endpoint),
        "Precondition: anonymous POST to {$endpoint} is forbidden on a pre-fix site"
      );
    }

    $this->runBackfillUpdate();

    foreach (self::AI_ENDPOINTS as $endpoint) {
      $status = $this->postStatus($endpoint);
      $this->assertNotEquals(
        403, $status,
        "Anonymous POST to {$endpoint} must not be forbidden after the backfill"
      );
      $this->assertNotEquals(
        500, $status,
        "Anonymous POST to {$endpoint} must not crash after the backfill"
      );
    }
  }

  /**
   * Running the update where the permission is already held changes nothing.
   */
  public function testUpdateIsIdempotent(): void {
    $before = $this->rolePermissions(RoleInterface::ANONYMOUS_ID);

    // The site already has the permission from install: a first run has
    // nothing to do, and a second run must not double up on the first.
    $this->runBackfillUpdate();
    $this->runBackfillUpdate();

    $this->assertSame(
      $before,
      $this->rolePermissions(RoleInterface::ANONYMOUS_ID),
      'Running the update on a site that already has the permission must leave the role untouched'
    );

    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $role_id) {
      $permissions = $this->rolePermissions($role_id);
      $this->assertSame(
        1, count(array_keys($permissions, 'use scolta ai', TRUE)),
        "{$role_id} must hold 'use scolta ai' exactly once after repeated runs"
      );
    }
  }

  /**
   * Re-running the update after a backfill does not re-grant anything.
   */
  public function testSecondRunAfterBackfillIsANoOp(): void {
    $this->simulatePreFixSite();
    $this->runBackfillUpdate();
    $after_first = $this->rolePermissions(RoleInterface::ANONYMOUS_ID);

    $this->runBackfillUpdate();

    $this->assertSame(
      $after_first,
      $this->rolePermissions(RoleInterface::ANONYMOUS_ID),
      'A second run of the backfill must be a no-op'
    );
  }

  /**
   * Only the AI permission is touched; unrelated grants survive.
   */
  public function testUpdateLeavesOtherPermissionsAlone(): void {
    $this->simulatePreFixSite();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);

    $this->runBackfillUpdate();

    $this->assertContains(
      'access content',
      $this->rolePermissions(RoleInterface::ANONYMOUS_ID),
      'The backfill must not disturb permissions it did not grant'
    );
  }

  /**
   * Puts the site in the config state of an install that predates the grant.
   */
  private function simulatePreFixSite(): void {
    user_role_revoke_permissions(RoleInterface::ANONYMOUS_ID, ['use scolta ai']);
    user_role_revoke_permissions(RoleInterface::AUTHENTICATED_ID, ['use scolta ai']);
  }

  /**
   * Loads scolta.install and runs the backfill update hook.
   */
  private function runBackfillUpdate(): void {
    \Drupal::moduleHandler()->loadInclude('scolta', 'install');
    $this->assertTrue(
      function_exists('scolta_update_10001'),
      'scolta.install must define scolta_update_10001()'
    );
    scolta_update_10001();
  }

  /**
   * Returns a role's permissions, reading past the entity static cache.
   *
   * @param string $role_id
   *   The role ID.
   *
   * @return string[]
   *   The permission strings.
   */
  private function rolePermissions(string $role_id): array {
    \Drupal::entityTypeManager()->getStorage('user_role')->resetCache([$role_id]);
    $role = Role::load($role_id);
    $this->assertNotNull($role, "Role {$role_id} must exist");
    return $role->getPermissions();
  }

  /**
   * Whether a role holds the AI permission.
   *
   * @param string $role_id
   *   The role ID.
   *
   * @return bool
   *   TRUE when the role holds 'use scolta ai'.
   */
  private function roleHasAiPermission(string $role_id): bool {
    return in_array('use scolta ai', $this->rolePermissions($role_id), TRUE);
  }

  /**
   * POSTs an empty JSON body to a path and returns the HTTP status.
   *
   * @param string $path
   *   The URL path.
   *
   * @return int
   *   The response status code.
   */
  private function postStatus(string $path): int {
    $session = $this->getSession();
    $session->getDriver()->getClient()->request(
      'POST',
      $this->getAbsoluteUrl($path),
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([]),
    );

    return $session->getStatusCode();
  }

}
