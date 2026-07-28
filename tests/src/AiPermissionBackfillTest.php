<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins the update hook that backfills the anonymous AI permission grant.
 *
 * scolta_install() grants 'use scolta ai' to anonymous and authenticated, so
 * a fresh install serves AI overviews to visitors. That hook does not run on
 * an existing site, so without an update hook the fix reaches nobody who
 * already had the module installed. These are file-inspection tests — no
 * Drupal bootstrap required; the behavior itself is executed in
 * tests/src/Functional/AiPermissionBackfillFunctionalTest.php.
 */
class AiPermissionBackfillTest extends TestCase {

  private string $installSource;

  /**
   * The body of the backfill update hook.
   */
  private string $updateBody;

  protected function setUp(): void {
    $this->installSource = file_get_contents(dirname(__DIR__, 2) . '/scolta.install');
    $this->updateBody = $this->functionBody('scolta_update_10001');
  }

  // -------------------------------------------------------------------
  // The hook exists, and is numbered so existing sites actually run it.
  // -------------------------------------------------------------------

  public function testBackfillUpdateHookExists(): void {
    $this->assertMatchesRegularExpression(
      '/function scolta_update_10001\(/',
      $this->installSource,
      'scolta.install must define scolta_update_10001() to backfill the AI permission grant'
    );
  }

  public function testEveryUpdateHookOutranksTheDefaultSchemaVersion(): void {
    preg_match_all('/function scolta_update_(\d+)\(/', $this->installSource, $matches);
    $numbers = array_map('intval', $matches[1]);

    $this->assertNotEmpty($numbers, 'At least one update hook must exist');
    $this->assertSame(
      array_unique($numbers), $numbers,
      'Update hook numbers must be unique — a duplicate silently shadows one implementation'
    );
    foreach ($numbers as $number) {
      // Drupal records 8000 (SCHEMA_MIN) for a module that shipped no update
      // hooks. A number at or below that never runs on an existing site.
      $this->assertGreaterThan(
        8000, $number,
        "scolta_update_{$number}() is at or below the default schema version and would never run"
      );
    }
  }

  // -------------------------------------------------------------------
  // It grants the right permission to both roles.
  // -------------------------------------------------------------------

  public function testUpdateGrantsTheAiPermission(): void {
    $this->assertStringContainsString(
      "grantPermission('use scolta ai')",
      $this->updateBody,
      "The update hook must grant 'use scolta ai'"
    );
  }

  public function testUpdateCoversBothDefaultRoles(): void {
    $this->assertStringContainsString(
      'RoleInterface::ANONYMOUS_ID',
      $this->updateBody,
      'The update hook must backfill the anonymous role — the 403 this fixes is anonymous'
    );
    $this->assertStringContainsString(
      'RoleInterface::AUTHENTICATED_ID',
      $this->updateBody,
      'The update hook must backfill the authenticated role, matching scolta_install()'
    );
  }

  public function testUpdatePersistsTheRole(): void {
    $this->assertStringContainsString(
      '$role->save()',
      $this->updateBody,
      'A granted permission that is never saved leaves the site exactly as broken as before'
    );
  }

  // -------------------------------------------------------------------
  // It is a no-op where there is nothing to backfill.
  // -------------------------------------------------------------------

  public function testUpdateSkipsRolesThatAlreadyHaveThePermission(): void {
    $this->assertStringContainsString(
      "hasPermission('use scolta ai')",
      $this->updateBody,
      'The update hook must check the permission before granting it, so a re-run writes no config'
    );
  }

  public function testUpdateToleratesAMissingRole(): void {
    $this->assertMatchesRegularExpression(
      '/if \(!\$role \|\|/',
      $this->updateBody,
      'Role::load() returns NULL on a site that removed the role; the hook must not fatal on it'
    );
  }

  // -------------------------------------------------------------------
  // The fresh-install path is untouched.
  // -------------------------------------------------------------------

  public function testInstallHookStillGrantsThePermission(): void {
    $installBody = $this->functionBody('scolta_install');

    foreach (['RoleInterface::ANONYMOUS_ID', 'RoleInterface::AUTHENTICATED_ID'] as $role) {
      $this->assertStringContainsString(
        "user_role_grant_permissions({$role}, ['use scolta ai'])",
        $installBody,
        "scolta_install() must keep granting 'use scolta ai' to {$role} — the update hook backfills existing sites, it does not replace the install-time grant"
      );
    }
  }

  public function testRoleEntityIsImported(): void {
    $this->assertStringContainsString(
      'use Drupal\user\Entity\Role;',
      $this->installSource,
      'scolta.install must import the Role entity class it calls Role::load() on'
    );
  }

  /**
   * Extracts a top-level function body from the install file source.
   *
   * @param string $name
   *   The function name.
   *
   * @return string
   *   Everything between the function's opening and closing brace.
   */
  private function functionBody(string $name): string {
    $start = strpos($this->installSource, "function {$name}(");
    $this->assertNotFalse($start, "scolta.install must define {$name}()");

    $open = strpos($this->installSource, '{', $start);
    $this->assertNotFalse($open, "{$name}() must have a body");

    $depth = 0;
    $length = strlen($this->installSource);
    for ($i = $open; $i < $length; $i++) {
      $char = $this->installSource[$i];
      if ($char === '{') {
        $depth++;
      }
      elseif ($char === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($this->installSource, $open, $i - $open + 1);
        }
      }
    }

    $this->fail("Unbalanced braces while reading {$name}()");
  }

}
