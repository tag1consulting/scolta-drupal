<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Scolta API endpoints with real HTTP requests.
 *
 * @group scolta
 */
class ScoltaEndpointFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'node', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that AI endpoints require the 'use scolta ai' permission.
   */
  public function testEndpointsRequirePermission(): void {
    $endpoints = [
      '/api/scolta/v1/expand-query',
      '/api/scolta/v1/summarize',
      '/api/scolta/v1/followup',
    ];

    foreach ($endpoints as $endpoint) {
      $this->drupalGet($endpoint);
      // POST endpoints accessed via GET should return 4xx (403 or 405).
      $statusCode = $this->getSession()->getStatusCode();
      $this->assertTrue(
        $statusCode >= 400 && $statusCode < 500,
        "Endpoint {$endpoint} should reject anonymous access, got {$statusCode}"
      );
    }
  }

  /**
   * Tests that the expand endpoint validates input.
   */
  public function testExpandEndpointValidation(): void {
    $user = $this->drupalCreateUser(['use scolta ai']);
    $this->drupalLogin($user);

    // Empty query should fail.
    $response = $this->makeJsonPost('/api/scolta/v1/expand-query', ['query' => '']);
    $this->assertTrue(
      $response['status'] >= 400,
      'Empty query should be rejected'
    );

    // Too-long query should fail.
    $response = $this->makeJsonPost('/api/scolta/v1/expand-query', [
      'query' => str_repeat('a', 501),
    ]);
    $this->assertTrue(
      $response['status'] >= 400,
      'Query over 500 chars should be rejected'
    );
  }

  /**
   * Tests that the search block renders on a page.
   */
  public function testSearchBlockRenders(): void {
    $this->drupalCreateContentType(['type' => 'page']);
    $this->drupalCreateNode(['type' => 'page', 'title' => 'Search Page']);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    $this->drupalGet('/node/1');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#scolta-search');
    $this->assertSession()->responseContains('scolta.js');
    $this->assertSession()->responseContains('pagefindPath');
  }

  /**
   * Make a JSON POST request and return status + decoded body.
   *
   * @param string $path
   *   The URL path.
   * @param array $data
   *   The POST body data.
   *
   * @return array
   *   Array with 'status' and 'body' keys.
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
      'body' => json_decode($session->getPage()->getContent(), TRUE),
    ];
  }

}
