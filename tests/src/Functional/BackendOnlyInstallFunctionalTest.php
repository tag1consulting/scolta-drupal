<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * An index builder: scolta installed, scolta_ui not.
 *
 * The site that builds an index other sites consume. It must install at all —
 * before the split, scolta_install() reached for a scolta_ui service and a
 * scolta_ui permission, so this combination could not be stood up — and it
 * must expose its build surface without any of the frontend's.
 *
 * @group scolta
 */
class BackendOnlyInstallFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The container compiles and the build services are there.
   */
  public function testContainerCompilesWithoutTheFrontend(): void {
    $this->assertFalse(
      \Drupal::moduleHandler()->moduleExists('scolta_ui'),
      'This test is only meaningful with the frontend absent'
    );

    $this->assertNotNull(\Drupal::service('scolta.content_gatherer'));
    $this->assertNotNull(\Drupal::service('scolta.pagefind_builder'));
    $this->assertNotNull(\Drupal::service('scolta.index_locator'));

    // Nothing of the frontend's leaked into the backend's container.
    $this->assertFalse(\Drupal::hasService('scolta.ai_service'));
    $this->assertFalse(\Drupal::hasService('scolta.asset_deployer'));
    $this->assertFalse(\Drupal::hasService('scolta_ui.index_origin'));
  }

  /**
   * The index settings screen serves, behind the backend's own permission.
   */
  public function testIndexSettingsScreenServes(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer scolta']));

    $this->drupalGet('admin/config/search/scolta/index');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Index contents');
  }

  /**
   * The frontend's routes are simply not there.
   */
  public function testFrontendRoutesAreAbsent(): void {
    foreach (['admin/config/search/scolta', 'api/scolta/v1/health'] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(404);
    }
  }

  /**
   * Dismissing the rebuild notice redirects instead of crashing.
   *
   * The notice is the backend's and so is the route, but its no-destination
   * fallback used to build a URL for scolta.settings — a route only the
   * frontend defines. On this install combination Url::fromRoute() throws
   * RouteNotFoundException, so the dismiss link answered 500.
   */
  public function testDismissingTheRebuildNoticeDoesNotCrash(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer scolta']));

    \Drupal::state()->set('scolta.rebuild_notice', [
      'notice_id' => 'test_notice',
      'status' => 'ok',
      'message' => 'Rebuilt.',
    ]);

    // The route is CSRF-protected, and a token minted in the test runner does
    // not match the browser session's. Take the one the rendered notice
    // carries: the token is derived from the path alone, so it stays valid
    // when the query changes.
    $this->drupalGet('admin/config/search/scolta/index');
    $dismiss = $this->assertSession()->elementExists(
      'css',
      'a[href*="dismiss-rebuild-notice"]'
    );
    parse_str((string) parse_url($dismiss->getAttribute('href'), PHP_URL_QUERY), $query);
    $this->assertArrayHasKey('token', $query, 'The dismiss link must carry a CSRF token');

    // Deliberately without a destination: that is the path through the
    // controller that used to build a URL for a route only scolta_ui defines.
    $this->drupalGet('admin/config/search/scolta/dismiss-rebuild-notice', [
      'query' => ['notice_id' => 'test_notice', 'token' => $query['token']],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('admin/config/search/scolta/index');
  }

  /**
   * Installing the backend alone writes only the backend's config object.
   */
  public function testOnlyTheBuildSettingsAreInstalled(): void {
    $this->assertNotNull($this->config('scolta.settings')->get('pagefind.output_dir'));
    $this->assertTrue(
      $this->config('scolta_ui.settings')->isNew(),
      'A backend-only install must not create the frontend settings object'
    );
  }

  /**
   * No role is granted a permission this module does not define.
   */
  public function testNoFrontendPermissionIsGranted(): void {
    $permissions = \Drupal::service('user.permissions')->getPermissions();

    $this->assertArrayHasKey('administer scolta', $permissions);
    $this->assertArrayNotHasKey('administer scolta ui', $permissions);
    $this->assertArrayNotHasKey('use scolta ai', $permissions);
  }

}
