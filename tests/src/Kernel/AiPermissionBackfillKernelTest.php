<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Executes scolta_update_10001(), which backfills the AI permission grant.
 *
 * A historical hook, kept executing exactly what it did when it shipped: an
 * update hook that has run on a site is a record of a step that site took, not
 * a place to restate current policy. What it granted the anonymous role is
 * revoked again by scolta_update_10004(), covered separately; what it granted
 * the authenticated role is still what a fresh install grants.
 *
 * Each test recreates the pre-hook state by revoking the permission from a
 * freshly installed site, which is the same config state an old site is in.
 * Assertions read the reloaded Role entity (RoleInterface::hasPermission())
 * rather than an AccountInterface — this was originally a functional test
 * with a fourth method that also checked whether an anonymous HTTP request
 * reached the AI endpoints after the update ran in the same process. That
 * method hit a real Drupal core caching quirk (a granted permission and an
 * account-level hasPermission() check in the same request can see stale data
 * from Drupal\Core\Session\AccessPolicyProcessor's static cache layer, which
 * a mid-request role save does not invalidate) and was deleted rather than
 * worked around, since no production request sequence does an update and an
 * access check in the same process. These three don't touch that path.
 *
 * @group scolta
 */
class AiPermissionBackfillKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta', 'user']);
    // Kernel tests enable a module via $modules without installing it, so
    // hook_install() (scolta_install()) never runs — including the grant of
    // 'use scolta ai' to the authenticated role that a real install performs.
    // Reproduce just that grant, matching the fresh-install baseline these
    // tests assume.
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['use scolta ai']);
  }

  /**
   * A fresh install grants the permission to authenticated users only.
   *
   * The regression guard on the install path. scolta_update_10001() still
   * grants both roles, because it is a record of a step sites already took
   * when the install hook did the same; scolta_update_10004() is what undoes
   * the anonymous half of it on those sites.
   */
  public function testFreshInstallGrantsTheAuthenticatedRoleOnly(): void {
    $this->assertTrue(
      $this->roleHasAiPermission(RoleInterface::AUTHENTICATED_ID),
      "A fresh install must grant 'use scolta ai' to authenticated users"
    );
    $this->assertFalse(
      $this->roleHasAiPermission(RoleInterface::ANONYMOUS_ID),
      "A fresh install must not grant 'use scolta ai' to the anonymous role"
    );
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
   * Running the update where the permission is already held changes nothing.
   */
  public function testUpdateIsIdempotent(): void {
    // Authenticated already holds it from install, so the first run has
    // nothing to do there; a second run must not double up on either role.
    $before = $this->rolePermissions(RoleInterface::AUTHENTICATED_ID);

    $this->runBackfillUpdate();
    $this->runBackfillUpdate();

    $this->assertSame(
      $before,
      $this->rolePermissions(RoleInterface::AUTHENTICATED_ID),
      'Running the update on a role that already has the permission must leave it untouched'
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

}
