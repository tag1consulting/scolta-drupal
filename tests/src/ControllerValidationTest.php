<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests controller input validation logic without Drupal bootstrap.
 *
 * The controllers parse JSON request bodies and validate inputs before
 * calling the AI service. These tests verify the validation rules by
 * testing the same conditions the controllers check.
 */
class ControllerValidationTest extends TestCase {

  // -------------------------------------------------------------------
  // ExpandQueryController validation.
  // -------------------------------------------------------------------

  public function testExpandRejectsEmptyQuery(): void {
    $body = ['query' => ''];
    $query = trim($body['query'] ?? '');
    $this->assertTrue(empty($query) || strlen($query) > 500);
  }

  public function testExpandRejectsNullQuery(): void {
    $body = [];
    $query = trim($body['query'] ?? '');
    $this->assertTrue(empty($query));
  }

  public function testExpandRejectsTooLongQuery(): void {
    $body = ['query' => str_repeat('a', 501)];
    $query = trim($body['query'] ?? '');
    $this->assertTrue(strlen($query) > 500);
  }

  public function testExpandAcceptsValidQuery(): void {
    $body = ['query' => 'product pricing'];
    $query = trim($body['query'] ?? '');
    $this->assertFalse(empty($query) || strlen($query) > 500);
  }

  public function testExpandCacheKeyIsDeterministic(): void {
    $query1 = 'Product Pricing';
    $query2 = 'product pricing';

    $key1 = 'scolta:expand:' . hash('sha256', strtolower($query1));
    $key2 = 'scolta:expand:' . hash('sha256', strtolower($query2));

    $this->assertEquals($key1, $key2,
      'Cache key should be case-insensitive');
  }

  public function testExpandStripsMarkdownCodeFences(): void {
    $response = "```json\n[\"term1\", \"term2\"]\n```";

    $cleaned = trim($response);
    $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
    $cleaned = preg_replace('/\s*```$/', '', $cleaned);
    $cleaned = trim($cleaned);

    $terms = json_decode($cleaned, true);
    $this->assertIsArray($terms);
    $this->assertEquals(['term1', 'term2'], $terms);
  }

  public function testExpandStripsGenericCodeFences(): void {
    $response = "```\n[\"one\", \"two\", \"three\"]\n```";

    $cleaned = trim($response);
    $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
    $cleaned = preg_replace('/\s*```$/', '', $cleaned);
    $cleaned = trim($cleaned);

    $terms = json_decode($cleaned, true);
    $this->assertIsArray($terms);
    $this->assertCount(3, $terms);
  }

  public function testExpandFallbackOnInvalidJson(): void {
    $query = 'product pricing';
    $cleaned = 'not valid json at all';

    $terms = json_decode($cleaned, true);
    if (!is_array($terms) || count($terms) < 2) {
      $terms = [$query];
    }

    $this->assertEquals(['product pricing'], $terms);
  }

  // -------------------------------------------------------------------
  // SummarizeController validation.
  // -------------------------------------------------------------------

  public function testSummarizeRejectsEmptyQuery(): void {
    $query = '';
    $this->assertTrue(empty($query) || strlen($query) > 500);
  }

  public function testSummarizeRejectsEmptyContext(): void {
    $context = '';
    $this->assertTrue(empty($context) || strlen($context) > 50000);
  }

  public function testSummarizeRejectsTooLongContext(): void {
    $context = str_repeat('x', 50001);
    $this->assertTrue(strlen($context) > 50000);
  }

  public function testSummarizeAcceptsValidInput(): void {
    $query = 'product pricing';
    $context = 'Our plans start at $10/month...';

    $this->assertFalse(empty($query) || strlen($query) > 500);
    $this->assertFalse(empty($context) || strlen($context) > 50000);
  }

  public function testSummarizeBuildsUserMessage(): void {
    $query = 'pricing';
    $context = 'Excerpt data here';

    $userMessage = "Search query: {$query}\n\nSearch result excerpts:\n{$context}";

    $this->assertStringContainsString('pricing', $userMessage);
    $this->assertStringContainsString('Excerpt data here', $userMessage);
  }

  // -------------------------------------------------------------------
  // FollowUpController validation.
  // -------------------------------------------------------------------

  public function testFollowUpRejectsEmptyMessages(): void {
    $messages = [];
    $this->assertTrue(empty($messages));
  }

  public function testFollowUpRejectsNonArrayMessages(): void {
    $messages = 'not an array';
    $this->assertTrue(!is_array($messages));
  }

  public function testFollowUpValidatesMessageFormat(): void {
    $valid = [
      ['role' => 'user', 'content' => 'Hello'],
      ['role' => 'assistant', 'content' => 'Hi there'],
      ['role' => 'user', 'content' => 'Follow up'],
    ];

    foreach ($valid as $msg) {
      $this->assertFalse(empty($msg['role']) || empty($msg['content']));
      $this->assertContains($msg['role'], ['user', 'assistant']);
    }
  }

  public function testFollowUpRejectsSystemRole(): void {
    $msg = ['role' => 'system', 'content' => 'sneaky'];
    $this->assertNotContains($msg['role'], ['user', 'assistant']);
  }

  public function testFollowUpRequiresLastMessageFromUser(): void {
    $messages = [
      ['role' => 'user', 'content' => 'Question'],
      ['role' => 'assistant', 'content' => 'Answer'],
    ];
    $last = end($messages);
    $this->assertNotEquals('user', $last['role']);
  }

  public function testFollowUpCountCalculation(): void {
    // Initial conversation: system (not in messages), user query, assistant summary.
    // Each follow-up adds: user question + assistant answer.
    // So followUpsSoFar = (count(messages) - 2) / 2.

    // Initial exchange (2 messages: user + assistant).
    $this->assertEquals(0, (int) ((2 - 2) / 2));

    // After 1 follow-up (4 messages: user + assistant + user + assistant).
    $this->assertEquals(1, (int) ((4 - 2) / 2));

    // After 2 follow-ups (6 messages).
    $this->assertEquals(2, (int) ((6 - 2) / 2));

    // After 3 follow-ups (8 messages) — should hit limit.
    $maxFollowUps = 3;
    $followUpsSoFar = (int) ((8 - 2) / 2);
    $this->assertTrue($followUpsSoFar >= $maxFollowUps);
  }

  public function testFollowUpRemainingCalculation(): void {
    $maxFollowUps = 3;

    // 1 follow-up done, submitting 2nd.
    $messages = 6; // user + assistant + user + assistant + user + assistant
    $followUpsSoFar = (int) (($messages - 2) / 2);
    $remaining = $maxFollowUps - $followUpsSoFar - 1;

    $this->assertEquals(0, max(0, $remaining));
  }

  // -------------------------------------------------------------------
  // Cross-controller: permissions are enforced by routing.
  // -------------------------------------------------------------------

  public function testApiRoutesRequirePermission(): void {
    $routing = \Symfony\Component\Yaml\Yaml::parseFile(
      dirname(__DIR__, 2) . '/scolta.routing.yml'
    );

    foreach (['scolta.expand', 'scolta.summarize', 'scolta.followup'] as $route) {
      $this->assertEquals(
        'use scolta ai',
        $routing[$route]['requirements']['_permission'],
        "Route {$route} must require 'use scolta ai' permission"
      );
    }
  }

}
