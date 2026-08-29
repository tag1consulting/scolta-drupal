<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\scolta\Access\AiAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the wiring that makes scolta.ai_access the single AI access decision.
 *
 * The value of the service is that both gates ask it: the block that tells
 * the browser a feature exists, and the route that serves the request the
 * browser then makes. Either one going back to reading config directly would
 * restore exactly the split this replaced, and would do it silently — the
 * behaviour tests in tests/src/Functional/AiAccessFunctionalTest.php cover
 * the shipped rule, but a site's decorator is the thing that would quietly
 * stop being consulted.
 */
class AiAccessWiringTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);

    // AccessResult::allowedIfHasPermission() asserts its cache contexts
    // through the container; a stub manager is enough to satisfy that
    // assertion without a full Drupal bootstrap.
    $cacheContextsManager = $this->createStub(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
  }

  /**
   * The route access check is tagged, or `_scolta_ai` silently allows.
   *
   * An untagged access check is not an error Drupal reports: the requirement
   * simply matches nothing, and every AI route falls back to the permission
   * alone.
   */
  public function testRouteAccessCheckIsTagged(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml')['services'];

    $this->assertArrayHasKey('scolta.ai_feature_access_check', $services);
    $this->assertContains(
      ['name' => 'access_check', 'applies_to' => '_scolta_ai'],
      $services['scolta.ai_feature_access_check']['tags'],
      'The access check must be tagged access_check/_scolta_ai or the route requirement does nothing'
    );
  }

  /**
   * Every AI route carries both the permission and the feature requirement.
   */
  public function testAiRoutesCarryBothRequirements(): void {
    $routing = Yaml::parseFile($this->moduleRoot . '/scolta.routing.yml');

    $expected = [
      'scolta.expand' => 'expand',
      'scolta.summarize' => 'summarize',
      'scolta.followup' => 'follow_up',
    ];

    foreach ($expected as $route => $feature) {
      $requirements = $routing[$route]['requirements'];
      $this->assertSame(
        'use scolta ai',
        $requirements['_permission'],
        "Route {$route} must keep its permission requirement — the feature check adds to it, it does not replace it"
      );
      $this->assertSame(
        $feature,
        $requirements['_scolta_ai'],
        "Route {$route} must name the feature it serves"
      );
    }
  }

  /**
   * An account with the permission is allowed; one without is not.
   *
   * Also asserts the cacheability the shipped rule attaches: 'user.permissions'
   * must be in the result's cache contexts, or the block that queries this
   * caches one visitor's answer for everyone else.
   */
  public function testAccessIsGrantedOnlyWithThePermission(): void {
    $access = new AiAccess();

    $allowed = $access->access($this->accountWithPermission(TRUE), AiAccess::FEATURE_SUMMARIZE);
    $this->assertTrue($allowed->isAllowed());
    $this->assertContains('user.permissions', $allowed->getCacheContexts());

    $notAllowed = $access->access($this->accountWithPermission(FALSE), AiAccess::FEATURE_SUMMARIZE);
    $this->assertFalse($notAllowed->isAllowed());
    $this->assertContains('user.permissions', $notAllowed->getCacheContexts());
  }

  /**
   * An unrecognised feature is refused outright, permission notwithstanding.
   *
   * A typo in a route requirement must not silently open the endpoint to
   * everyone with the base permission.
   */
  public function testUnrecognisedFeatureIsForbiddenEvenWithThePermission(): void {
    $access = new AiAccess();

    $result = $access->access($this->accountWithPermission(TRUE), 'not_a_real_feature');

    $this->assertTrue($result->isForbidden());
  }

  private function accountWithPermission(bool $hasPermission): AccountInterface {
    return new class($hasPermission) implements AccountInterface {

      public function __construct(private readonly bool $hasPermission) {
      }

      public function getRoles($exclude_locked_roles = FALSE) {
        return [];
      }

      public function hasPermission($permission) {
        return $this->hasPermission;
      }

      public function hasRole($rid) {
        return FALSE;
      }

      public function isAuthenticated() {
        return TRUE;
      }

      public function isAnonymous() {
        return FALSE;
      }

      public function id() {
        return 1;
      }

      public function getEmail() {
        return NULL;
      }

      public function getAccountName() {
        return 'test';
      }

      public function getDisplayName() {
        return 'test';
      }

      public function getTimeZone() {
        return 'UTC';
      }

      public function getLastAccessedTime() {
        return 0;
      }

      public function getPreferredLangcode($fallback_to_default = TRUE) {
        return 'en';
      }

      public function getPreferredAdminLangcode($fallback_to_default = TRUE) {
        return 'en';
      }

      public function getUsername() {
        return 'test';
      }

    };
  }

}
