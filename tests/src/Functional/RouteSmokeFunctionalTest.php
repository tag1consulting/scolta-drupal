<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Smoke-tests every route defined in scolta.routing.yml.
 *
 * Reads the routing file at runtime so any newly-added route is automatically
 * covered on the next CI run — no manual test-list updates needed. This is
 * the guard that catches controller crashes, render-pipeline errors, and
 * RouteNotFoundException before they reach production.
 *
 * Why BrowserTestBase and not KernelTestBase: the RouteNotFoundException crash
 * that prompted this test occurred inside HtmlRenderer->buildPageTopAndBottom(),
 * which is part of the full HTTP render pipeline. KernelTestBase does not boot
 * the render pipeline and would not have caught it. BrowserTestBase makes real
 * HTTP requests through the full stack.
 *
 * @group scolta
 */
class RouteSmokeFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'node', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin user holding all scolta permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser([
      'administer scolta',
      'use scolta ai',
      'access administration pages',
    ]);
  }

  /**
   * Every GET route must return non-500 for an authenticated admin.
   *
   * A 500 here means the controller, a hook_page_top implementation, or the
   * render pipeline itself crashed — exactly the class of error that a
   * missing route reference or broken controller produces.
   */
  public function testAllGetRoutesReturnNon500AsAdmin(): void {
    $this->drupalLogin($this->adminUser);
    foreach ($this->loadGetRoutes() as $routeName => [$path]) {
      $this->drupalGet($path);
      $code = $this->getSession()->getStatusCode();
      $this->assertNotEquals(
        500, $code,
        "Route {$routeName} ({$path}) returned HTTP 500 — controller or render pipeline crashed."
      );
    }
  }

  /**
   * Every GET route with a permission requirement must deny anonymous access.
   *
   * Ensures permissions.yml and routing.yml stay consistent: a route that
   * declares _permission must block unauthenticated requests with 302 or 403,
   * not silently serve content (200) or crash (500).
   */
  public function testPermissionedGetRoutesDenyAnonymous(): void {
    foreach ($this->loadGetRoutes() as $routeName => [$path, $permission]) {
      if ($permission === '') {
        continue;
      }
      $this->drupalGet($path);
      $code = $this->getSession()->getStatusCode();
      $this->assertContains(
        $code, [302, 403],
        "Route {$routeName} ({$path}) should deny anonymous access (302 or 403), got {$code}."
      );
      $this->assertNotEquals(
        500, $code,
        "Route {$routeName} ({$path}) returned 500 for anonymous — must not crash before access check."
      );
    }
  }

  /**
   * Every POST route must return structured JSON 4xx on an authenticated
   * request with an empty body — not 500, not an HTML error page.
   *
   * An HTML error page in response to a POST /api/* means a PHP fatal or
   * uncaught exception slipped through the JSON error handler.
   */
  public function testAllPostRoutesReturnStructuredErrorOnEmptyBody(): void {
    $this->drupalLogin($this->adminUser);
    foreach ($this->loadPostRoutes() as $routeName => [$path]) {
      $response = $this->makeJsonPost($path, []);
      $this->assertNotEquals(
        500, $response['status'],
        "POST route {$routeName} ({$path}) returned 500 on empty body — controller crashed."
      );
      $this->assertGreaterThanOrEqual(
        400, $response['status'],
        "POST route {$routeName} ({$path}) should return 4xx for an empty/invalid body."
      );
      $this->assertNotNull(
        $response['body'],
        "POST route {$routeName} ({$path}) must return valid JSON, not an HTML page."
      );
      $this->assertArrayHasKey(
        'error', $response['body'],
        "POST route {$routeName} ({$path}) error response must contain an 'error' key."
      );
    }
  }

  /**
   * Returns all GET routes from scolta.routing.yml without path parameters.
   *
   * @return array<string, array{string, string}>
   *   Route name => [path, permission].
   */
  private function loadGetRoutes(): array {
    return $this->loadRoutes('GET');
  }

  /**
   * Returns all POST-only routes from scolta.routing.yml.
   *
   * @return array<string, array{string, string}>
   *   Route name => [path, permission].
   */
  private function loadPostRoutes(): array {
    return $this->loadRoutes('POST');
  }

  /**
   * Parses scolta.routing.yml and returns routes matching the given method.
   *
   * Routes with path parameters (e.g. {node}) are excluded — they require
   * real entity IDs and are out of scope for a generic smoke test.
   *
   * @param string $method
   *   HTTP method to filter on, 'GET' or 'POST'.
   *
   * @return array<string, array{string, string}>
   *   Route name => [path, permission].
   */
  private function loadRoutes(string $method): array {
    // tests/src/Functional is three levels below the module root.
    $routingFile = dirname(__DIR__, 3) . '/scolta.routing.yml';
    $this->assertFileExists($routingFile, 'scolta.routing.yml not found at module root');

    $routing = Yaml::parseFile($routingFile);
    $routes = [];

    foreach ($routing as $routeName => $def) {
      if (str_contains($def['path'], '{')) {
        continue;
      }

      $declaredMethods = $def['methods'] ?? ['GET'];

      if ($method === 'POST') {
        if ($declaredMethods === ['POST']) {
          $routes[$routeName] = [$def['path'], $def['requirements']['_permission'] ?? ''];
        }
      }
      else {
        if (in_array($method, $declaredMethods, TRUE)) {
          $routes[$routeName] = [$def['path'], $def['requirements']['_permission'] ?? ''];
        }
      }
    }

    $this->assertNotEmpty(
      $routes,
      "No {$method} routes found in scolta.routing.yml — the YAML parser may have failed."
    );
    return $routes;
  }

  /**
   * Makes a JSON POST request and returns the HTTP status and decoded body.
   *
   * @param string $path
   *   Request path.
   * @param array $data
   *   JSON-encodable request body.
   *
   * @return array{status: int, body: array|null}
   */
  protected function makeJsonPost(string $path, array $data): array {
    $url = $this->getAbsoluteUrl($path);
    $session = $this->getSession();
    $session->getDriver()->getClient()->request(
      'POST',
      $url,
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($data),
    );
    return [
      'status' => $session->getStatusCode(),
      'body'   => json_decode($session->getPage()->getContent(), TRUE),
    ];
  }

}
