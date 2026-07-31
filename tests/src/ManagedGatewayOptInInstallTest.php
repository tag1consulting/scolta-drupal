<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Installing the module enables no AI provider and opens no AI endpoint.
 *
 * Two defaults used to be applied at install time and are not any more: the
 * managed Amazee.ai gateway, which is now enabled only when an operator
 * selects it and completes the connect flow; and the 'use scolta ai' grant to
 * the anonymous role, which left the cost-bearing AI endpoints reachable by
 * unauthenticated traffic.
 *
 * These are file-inspection tests — no Drupal bootstrap required. The
 * behavior itself is executed in
 * tests/src/Functional/ManagedGatewayOptInFunctionalTest.php.
 */
class ManagedGatewayOptInInstallTest extends TestCase {

  private string $installSource;

  /**
   * The body of hook_install().
   */
  private string $installBody;

  protected function setUp(): void {
    $this->installSource = file_get_contents(dirname(__DIR__, 2) . '/scolta.install');
    $this->installBody = $this->functionBody('scolta_install');
  }

  // -------------------------------------------------------------------
  // Install enables no managed gateway.
  // -------------------------------------------------------------------

  public function testInstallDoesNotEnableTheManagedGateway(): void {
    foreach (['AutoProvisioner', 'ensureAiAvailable(', 'amazee_config_storage'] as $needle) {
      $this->assertStringNotContainsString(
        $needle,
        $this->installSource,
        "scolta.install must not reference {$needle}: the managed gateway is enabled from the AI settings screen, never at install"
      );
    }
  }

  public function testInstallStoresNoCredentials(): void {
    $this->assertStringNotContainsString(
      'scolta.amazee.credentials',
      $this->installBody,
      'scolta_install() must write no managed-gateway credentials'
    );
  }

  public function testInstallSelectsNoProvider(): void {
    $this->assertStringNotContainsString(
      'ai_provider',
      $this->installBody,
      'scolta_install() must leave the AI provider at its shipped config default'
    );
  }

  // -------------------------------------------------------------------
  // Install does not open the AI endpoints to anonymous traffic.
  // -------------------------------------------------------------------

  public function testInstallDoesNotGrantAnonymousAiAccess(): void {
    $this->assertStringNotContainsString(
      'RoleInterface::ANONYMOUS_ID',
      $this->installBody,
      "scolta_install() must not grant 'use scolta ai' to the anonymous role — the AI endpoints make cost-bearing calls"
    );
  }

  public function testInstallStillGrantsAuthenticatedAiAccess(): void {
    $this->assertStringContainsString(
      "user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['use scolta ai'])",
      $this->installBody,
      'Logged-in AI search is intended out of the box; the authenticated grant stays'
    );
  }

  // -------------------------------------------------------------------
  // The update hook carries existing sites onto both rules.
  // -------------------------------------------------------------------

  public function testUpdateHookExistsAndOutranksEveryEarlierOne(): void {
    preg_match_all('/function scolta_update_(\d+)\(/', $this->installSource, $matches);
    $numbers = array_map('intval', $matches[1]);

    $this->assertContains(
      10004,
      $numbers,
      'scolta.install must define scolta_update_10004() so existing sites are carried over'
    );
    $this->assertSame(
      10004,
      max($numbers),
      'The new update hook must be the highest-numbered one, or sites that already ran a higher number skip it'
    );
    $this->assertSame(
      array_unique($numbers),
      $numbers,
      'Update hook numbers must be unique — a duplicate silently shadows one implementation'
    );
  }

  public function testUpdateSelectsTheGatewayForALegacyConnectedSite(): void {
    $body = $this->functionBody('scolta_update_10004');

    $this->assertStringContainsString(
      "\$config->set('ai_provider', 'amazee')",
      $body,
      'A site whose traffic already went through the stored connection must keep working'
    );
    $this->assertStringContainsString(
      'scolta.amazee.credentials',
      $body,
      'The migration must be scoped to sites that actually hold a stored connection'
    );
  }

  public function testUpdateLeavesAProviderAloneWhenAnExplicitKeyIsConfigured(): void {
    $body = $this->functionBody('scolta_update_10004');

    $this->assertStringContainsString(
      '!_scolta_has_explicit_api_key()',
      $body,
      'A site with its own key was never on the managed gateway; its provider must not be touched'
    );
  }

  public function testUpdateSkipsAProviderThatIsAlreadySelected(): void {
    $body = $this->functionBody('scolta_update_10004');

    $this->assertStringContainsString(
      "\$provider !== 'amazee' && \$provider !== 'drupal_ai'",
      $body,
      "The migration must skip a site already on 'amazee', and one on 'drupal_ai' which manages its own provider"
    );
  }

  public function testUpdateRevokesTheAnonymousGrant(): void {
    $body = $this->functionBody('scolta_update_10004');

    $this->assertStringContainsString(
      "revokePermission('use scolta ai')",
      $body,
      "The update must revoke 'use scolta ai' from the anonymous role on an existing site"
    );
    $this->assertStringContainsString(
      'RoleInterface::ANONYMOUS_ID',
      $body,
      'The revoke must name the anonymous role'
    );
    $this->assertStringNotContainsString(
      'RoleInterface::AUTHENTICATED_ID',
      $body,
      'The authenticated grant is intended and must survive the update'
    );
    $this->assertStringContainsString(
      '$anonymous->save()',
      $body,
      'A revoked permission that is never saved leaves the site exactly as open as before'
    );
  }

  public function testUpdateToleratesAMissingAnonymousRole(): void {
    $body = $this->functionBody('scolta_update_10004');

    $this->assertMatchesRegularExpression(
      '/if \(\$anonymous &&/',
      $body,
      'Role::load() returns NULL on a site that removed the role; the hook must not fatal on it'
    );
  }

  public function testUpdateCarriesAPolicyComment(): void {
    $source = $this->installSource;
    $pos = strpos($source, 'function scolta_update_10004(');
    $this->assertNotFalse($pos, 'scolta.install must define scolta_update_10004()');

    // The docblock immediately above the hook.
    $docStart = strrpos(substr($source, 0, $pos), '/**');
    $this->assertNotFalse($docStart, 'The update hook must be documented');
    $doc = substr($source, $docStart, $pos - $docStart);

    $this->assertStringContainsString(
      'POLICY:',
      $doc,
      'The update hook must record why the rules it migrates sites onto exist'
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
