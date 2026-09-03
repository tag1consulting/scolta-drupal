<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Flood control on the AI API endpoints.
 *
 * The three /api/scolta/v1/* routes make cost-bearing LLM calls. Requests
 * beyond the configured per-IP threshold must be rejected with HTTP 429
 * before any AI work happens.
 *
 * The requests are made as a logged-in user because the flood check lives in
 * the controller, behind the route's 'use scolta ai' permission: an anonymous
 * request is refused before the flood layer is reached, so it would prove
 * nothing about throttling. The thresholds themselves are per-IP and
 * site-wide, not per-account.
 *
 * @group scolta
 */
class AiEndpointFloodFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Fake managed-gateway credentials pointing at a closed port, with the
    // provider selected so they are actually in play: requests resolve
    // instantly without real network traffic, and AI failures map to the
    // normal error shape — which is all this test needs underneath the flood
    // layer.
    \Drupal::state()->set('scolta.amazee.credentials', [
      'litellm_token' => 'test-token',
      'litellm_api_url' => 'http://127.0.0.1:1',
      'region' => 'test',
    ]);
    $this->config('scolta.settings')
      ->set('ai_provider', 'amazee')
      ->set('amazee_model', 'claude-4-5-sonnet')
      ->save();

    $this->drupalLogin($this->drupalCreateUser(['use scolta ai']));
  }

  /**
   * Requests beyond the per-IP threshold get HTTP 429.
   */
  public function testPerIpThresholdReturns429(): void {
    $this->config('scolta.settings')
      ->set('flood.ai_ip_limit', 2)
      ->set('flood.ai_ip_window', 60)
      ->save();

    // Requests 1 and 2 pass the flood layer (whatever the AI layer says,
    // it must not be 429).
    foreach ([1, 2] as $i) {
      $response = $this->makeJsonPost('/api/scolta/v1/expand-query', ['query' => 'flood test ' . $i]);
      $this->assertNotEquals(429, $response['status'],
        "Request {$i} is within the threshold and must not be throttled");
    }

    // Request 3 exceeds the threshold.
    $response = $this->makeJsonPost('/api/scolta/v1/expand-query', ['query' => 'flood test 3']);
    $this->assertEquals(429, $response['status'],
      'The third request must be rejected by the per-IP flood threshold');
    $this->assertNotNull($response['body']);
    $this->assertArrayHasKey('error', $response['body']);
    $this->assertStringContainsString('Too many requests', $response['body']['error']);
  }

  /**
   * The global threshold throttles independently of the per-IP layer.
   */
  public function testGlobalThresholdReturns429(): void {
    $this->config('scolta.settings')
      ->set('flood.ai_ip_limit', 100)
      ->set('flood.ai_global_limit', 1)
      ->set('flood.ai_global_window', 60)
      ->save();

    $first = $this->makeJsonPost('/api/scolta/v1/summarize', ['query' => 'q', 'context' => 'c']);
    $this->assertNotEquals(429, $first['status']);

    $second = $this->makeJsonPost('/api/scolta/v1/summarize', ['query' => 'q', 'context' => 'c']);
    $this->assertEquals(429, $second['status'],
      'The second request must trip the global threshold of 1');
  }

  /**
   * A threshold of 0 disables that flood layer.
   */
  public function testZeroLimitDisablesLayer(): void {
    $this->config('scolta.settings')
      ->set('flood.ai_ip_limit', 0)
      ->set('flood.ai_global_limit', 0)
      ->save();

    for ($attempt = 0; $attempt < 4; $attempt++) {
      $response = $this->makeJsonPost('/api/scolta/v1/followup', ['messages' => []]);
      $this->assertNotEquals(429, $response['status'],
        'With both layers disabled no request may be throttled');
    }
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
