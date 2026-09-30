<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;

/**
 * Tests the Scolta API endpoints with real HTTP requests.
 *
 * @group scolta
 */
class ScoltaEndpointFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'scolta_chat_test', 'node', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * GET requests to POST-only endpoints must return 4xx, not 200 or 500.
   *
   * Verifies method enforcement on the AI API routes. These are POST-only;
   * a GET by any user (anonymous or authenticated) must be rejected with
   * 403, 404, or 405 — never 200 or 500. Bundled with each endpoint's input
   * validation below in one install, since both are cheap per-endpoint
   * checks that need only one logged-in user.
   */
  public function testEndpointsRejectInvalidRequests(): void {
    foreach ([
      '/api/scolta/v1/expand-query',
      '/api/scolta/v1/summarize',
      '/api/scolta/v1/followup',
    ] as $endpoint) {
      $this->drupalGet($endpoint);
      $statusCode = $this->getSession()->getStatusCode();
      $this->assertTrue(
        $statusCode >= 400 && $statusCode < 500,
        "GET to POST-only endpoint {$endpoint} should return 4xx, got {$statusCode}"
      );
    }

    $user = $this->drupalCreateUser(['use scolta ai']);
    $this->drupalLogin($user);

    // Empty query should fail.
    $response = $this->makeJsonPost('/api/scolta/v1/expand-query', ['query' => '']);
    $this->assertTrue($response['status'] >= 400, 'Empty query should be rejected');

    // Too-long query should fail.
    $response = $this->makeJsonPost('/api/scolta/v1/expand-query', [
      'query' => str_repeat('a', 501),
    ]);
    $this->assertTrue($response['status'] >= 400, 'Query over 500 chars should be rejected');

    // A query with no context should fail.
    $response = $this->makeJsonPost('/api/scolta/v1/summarize', ['query' => 'test']);
    $this->assertTrue($response['status'] >= 400, 'Summarize with no context should be rejected');

    // A message array missing the expected shape should fail.
    $response = $this->makeJsonPost('/api/scolta/v1/followup', [
      'messages' => [['invalid' => 'format']],
    ]);
    $this->assertTrue($response['status'] >= 400, 'Malformed follow-up message shape should be rejected');
  }

  /**
   * The AI endpoints are closed to anonymous traffic out of the box.
   *
   * hook_install() grants 'use scolta ai' to the authenticated role only.
   * These endpoints make cost-bearing LLM calls, so serving them to
   * unauthenticated visitors is a decision a site makes rather than a default
   * it inherits: a site that wants it grants the permission to the anonymous
   * role at Administration › People › Permissions.
   */
  public function testAiEndpointsDenyAnonymousByDefault(): void {
    $endpoints = [
      '/api/scolta/v1/expand-query',
      '/api/scolta/v1/summarize',
      '/api/scolta/v1/followup',
    ];

    foreach ($endpoints as $endpoint) {
      $response = $this->makeJsonPost($endpoint, []);
      $this->assertEquals(
        403, $response['status'],
        "Anonymous POST to {$endpoint} must be forbidden — 'use scolta ai' is not granted to anonymous at install"
      );
    }
  }

  /**
   * Logged-in visitors reach the AI endpoints out of the box.
   *
   * The other half of the install-time grant: authenticated AI search is
   * intended, so the permission check must pass without any admin action. A
   * POST with an invalid body returns a 4xx of its own, so the assertion is
   * specifically about it not being 403.
   */
  public function testAiEndpointsAllowAuthenticatedByDefault(): void {
    $this->drupalLogin($this->drupalCreateUser([]));

    foreach ([
      '/api/scolta/v1/expand-query',
      '/api/scolta/v1/summarize',
      '/api/scolta/v1/followup',
    ] as $endpoint) {
      $response = $this->makeJsonPost($endpoint, []);
      $this->assertNotEquals(
        403, $response['status'],
        "Authenticated POST to {$endpoint} should not be forbidden — 'use scolta ai' is granted at install"
      );
      $this->assertNotEquals(
        500, $response['status'],
        "Authenticated POST to {$endpoint} must not crash"
      );
    }
  }

  /**
   * The max_follow_ups quota rejects a follow-up once exhausted.
   *
   * A quota of 0 means every follow-up is over budget, so this is the
   * cheapest way to observe the config-driven limit taking effect without
   * needing to first exhaust a real conversation history.
   */
  public function testFollowUpLimitEnforced(): void {
    $user = $this->drupalCreateUser(['use scolta ai']);
    $this->drupalLogin($user);

    $this->config('scolta.settings')->set('max_follow_ups', 0)->save();

    $response = $this->makeJsonPost('/api/scolta/v1/followup', [
      'messages' => [
        ['role' => 'user', 'content' => 'initial question'],
        ['role' => 'assistant', 'content' => 'initial answer'],
        ['role' => 'user', 'content' => 'follow-up'],
      ],
    ]);
    $this->assertEquals(429, $response['status']);
  }

  /**
   * The chat answers only when on, to a permitted visitor, with its headers.
   *
   * Signed in visitors carry a session, so Drupal's CSRF header is required
   * on top of X-Scolta-Chat; anonymous visitors have no session and rely on
   * X-Scolta-Chat, which the handler requires of everyone. scolta_chat_test
   * answers the model calls, and no Drupal AI module is installed.
   */
  public function testChatFollowsTheSwitchThePermissionAndTheHeaders(): void {
    $this->drupalLogin($this->drupalCreateUser([]));
    $token = $this->csrfToken();
    $both = ['HTTP_X_SCOLTA_CHAT' => '1', 'HTTP_X_CSRF_TOKEN' => $token];

    $this->assertSame(404, $this->chatRequest('POST', 'plan', ['message' => 'x'], $both)['status'], 'The chat is off at install');

    $this->config('scolta.settings')->set('chat_enabled', TRUE)->save();
    $this->assertSame(403, $this->chatRequest('POST', 'plan', ['message' => 'x'], ['HTTP_X_SCOLTA_CHAT' => '1'])['status'], "A session without Drupal's CSRF header is refused");
    $plan = $this->chatRequest('POST', 'plan', ['message' => 'Any fines?'], $both);
    $this->assertSame(200, $plan['status']);
    $this->assertSame('planned query', $plan['body']['query']);

    $this->drupalLogout();
    $this->assertSame(403, $this->chatRequest('POST', 'plan', ['message' => 'x'], ['HTTP_X_SCOLTA_CHAT' => '1'])['status'], 'Anonymous visitors lack the permission at install');

    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['use scolta ai']);
    $missing = $this->chatRequest('POST', 'plan', ['message' => 'x'], []);
    $this->assertSame(403, $missing['status']);
    $this->assertSame('Missing X-Scolta-Chat header', $missing['body']['error']);
  }

  /**
   * An anonymous chat streams, owns its thread by cookie and starts no session.
   */
  public function testAnonymousChatStreamsWithAScopedCookieAndNoSession(): void {
    $this->config('scolta.settings')->set('chat_enabled', TRUE)->save();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['use scolta ai']);
    $sessionsBefore = $this->sessionCount();

    $turn = $this->chatRequest('POST', 'turn', [
      'message' => 'What does GDPR say about breach notification?',
      'needs_search' => TRUE,
      'pages' => [['tier' => 1, 'title' => 'GDPR', 'url' => $this->getAbsoluteUrl('/gdpr'), 'excerpt' => 'Notify within 72 hours.']],
    ], ['HTTP_X_SCOLTA_CHAT' => '1', 'HTTP_ACCEPT' => 'text/event-stream']);

    $this->assertSame(200, $turn['status']);
    $this->assertStringContainsString('text/event-stream', $turn['headers']['content-type'][0] ?? '');
    $events = $this->parseEvents($turn['raw']);
    $this->assertSame(['thread', 'delta', 'delta', 'sources', 'done'], array_column($events, 0));
    $this->assertSame("[[1]]({$this->getAbsoluteUrl('/gdpr')}).\nSecond line.", $events[2][1]['text'], 'A newline in model text stays inside one event');
    $this->assertSame([['n' => 1, 'title' => 'GDPR', 'url' => $this->getAbsoluteUrl('/gdpr')]], $events[3][1]['pages']);
    $threadId = $events[0][1]['thread_id'];

    $cookie = $this->chatCookie($turn['headers']);
    $this->assertNotNull($cookie, 'The first chat response sets the owner cookie');
    $this->assertMatchesRegularExpression('/^scolta_chat=[0-9a-f]{64};/', $cookie);
    $this->assertStringContainsString('path=/api/scolta/v1/chat', $cookie);
    $this->assertStringContainsStringIgnoringCase('httponly', $cookie);
    $this->assertStringContainsStringIgnoringCase('samesite=lax', $cookie);
    $this->assertStringContainsStringIgnoringCase('max-age=86400', $cookie);

    foreach ($turn['headers']['set-cookie'] ?? [] as $header) {
      $this->assertStringNotContainsString('SESS', $header, 'No session cookie for an anonymous chat');
    }
    $this->assertSame($sessionsBefore, $this->sessionCount());

    $history = $this->chatRequest('GET', 'thread', NULL, ['HTTP_X_SCOLTA_CHAT' => '1']);
    $this->assertSame($threadId, $history['body']['thread_id']);
    $this->assertCount(2, $history['body']['messages']);
    $this->assertNull($this->chatCookie($history['headers']), 'A known visitor gets no new cookie');

    // Another visitor with the same thread id reads nothing.
    $this->getSession()->getDriver()->getClient()->getCookieJar()->clear();
    $stranger = $this->chatRequest('GET', 'thread', NULL, ['HTTP_X_SCOLTA_CHAT' => '1'], '?thread_id=' . $threadId);
    $this->assertSame([], $stranger['body']['messages']);

    // Page responses never carry the chat cookie.
    $this->drupalGet('<front>');
    $this->assertNull($this->chatCookie(array_change_key_case($this->getSession()->getResponseHeaders())));
  }

  /**
   * A chat request with raw access to the response.
   *
   * @return array{status: int, body: array|null, raw: string, headers: array<string, string[]>}
   *   Status, decoded body, raw body and lower-cased headers.
   */
  protected function chatRequest(string $method, string $route, ?array $data, array $server, string $query = ''): array {
    $session = $this->getSession();
    $session->getDriver()->getClient()->request(
      $method,
      $this->getAbsoluteUrl('/api/scolta/v1/chat/' . $route . $query),
      [],
      [],
      $server + ['CONTENT_TYPE' => 'application/json'],
      $data === NULL ? NULL : json_encode($data),
    );
    $raw = $session->getPage()->getContent();

    return [
      'status' => $session->getStatusCode(),
      'body' => json_decode($raw, TRUE),
      'raw' => $raw,
      'headers' => array_change_key_case($session->getResponseHeaders()),
    ];
  }

  /**
   * The scolta_chat Set-Cookie header, if a response has one.
   */
  protected function chatCookie(array $headers): ?string {
    foreach ($headers['set-cookie'] ?? [] as $header) {
      if (str_starts_with($header, 'scolta_chat=')) {
        return $header;
      }
    }
    return NULL;
  }

  /**
   * Parse a text/event-stream body into [event, data] pairs.
   */
  protected function parseEvents(string $raw): array {
    $events = [];
    foreach (array_filter(explode("\n\n", $raw)) as $block) {
      if (preg_match('/^event: (\w+)\ndata: (.*)$/s', $block, $m)) {
        $events[] = [$m[1], json_decode($m[2], TRUE)];
      }
    }
    return $events;
  }

  /**
   * Stored sessions; the table only exists once a session has been written.
   */
  protected function sessionCount(): int {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('sessions')) {
      return 0;
    }
    return (int) $database->select('sessions')->countQuery()->execute()->fetchField();
  }

  /**
   * The CSRF token for the signed in user's session.
   */
  protected function csrfToken(): string {
    $this->drupalGet('session/token');
    return $this->getSession()->getPage()->getContent();
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
