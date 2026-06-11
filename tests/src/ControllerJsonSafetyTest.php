<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for controller security and correctness fixes.
 *
 * Verifies via source analysis (no Drupal bootstrap required):
 *   - JSON decoding goes through the shared parseJsonBody() (scolta-php
 *     AiControllerTrait), which uses JSON_THROW_ON_ERROR and returns the
 *     shared 400 error shape — controllers no longer hand-roll json_decode
 *   - Sensitive data is not logged (raw AI responses, print_r output)
 *   - Exception stack traces are preserved in logger calls
 *   - Exception details are not leaked in HTTP error responses
 *
 * The shared request pipeline lives in AiApiControllerBase; the three
 * endpoint controllers only implement invokeHandler().
 */
class ControllerJsonSafetyTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  /**
   * Data provider for all three endpoint controllers.
   */
  public static function controllerProvider(): array {
    $root = dirname(__DIR__, 2);
    return [
      'ExpandQueryController' => [
        'ExpandQueryController',
        $root . '/src/Controller/ExpandQueryController.php',
      ],
      'SummarizeController' => [
        'SummarizeController',
        $root . '/src/Controller/SummarizeController.php',
      ],
      'FollowUpController' => [
        'FollowUpController',
        $root . '/src/Controller/FollowUpController.php',
      ],
    ];
  }

  private function baseSource(): string {
    return file_get_contents($this->moduleRoot . '/src/Controller/AiApiControllerBase.php');
  }

  // -------------------------------------------------------------------
  // 1. JSON decoding is centralized in parseJsonBody() (scolta-php).
  // -------------------------------------------------------------------

  public function testBaseUsesSharedJsonBodyParser(): void {
    $contents = $this->baseSource();
    $this->assertStringContainsString(
      '$this->parseJsonBody(',
      $contents,
      'AiApiControllerBase::handle() must decode via the shared parseJsonBody() API'
    );
    $this->assertStringNotContainsString(
      'json_decode(',
      $contents,
      'The base must not hand-roll json_decode — parseJsonBody owns decoding (JSON_THROW_ON_ERROR + 400 shape)'
    );
  }

  public function testBaseMapsParseErrorsToErrorResponse(): void {
    $contents = $this->baseSource();
    $this->assertStringContainsString(
      "return new JsonResponse(['error' => \$parsed['error']], \$parsed['status']);",
      $contents,
      'Malformed JSON must map to the shared error shape and status (400)'
    );
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testControllersDoNotHandRollJsonDecode(string $className, string $file): void {
    $contents = file_get_contents($file);
    $this->assertStringNotContainsString(
      'json_decode(',
      $contents,
      "{$className} must not duplicate JSON decoding — the base pipeline owns it"
    );
  }

  // -------------------------------------------------------------------
  // 2. Sensitive data is not logged.
  // -------------------------------------------------------------------

  public function testExpandDoesNotLogRawResponse(): void {
    $contents = file_get_contents(
      $this->moduleRoot . '/src/Controller/ExpandQueryController.php'
    ) . $this->baseSource();
    $this->assertStringNotContainsString(
      'Expand raw response',
      $contents,
      'The expand pipeline must not log raw AI responses (sensitive data leak)'
    );
  }

  public function testPipelineDoesNotUsePrintR(): void {
    $contents = $this->baseSource();
    $this->assertStringNotContainsString(
      'print_r',
      $contents,
      'The AI request pipeline must not use print_r (sensitive data in logs)'
    );
  }

  // -------------------------------------------------------------------
  // 3. Error-logging preserves the exception object for stack traces.
  // -------------------------------------------------------------------

  public function testExceptionObjectPreservedInLog(): void {
    $contents = $this->baseSource();
    $this->assertStringContainsString(
      "'exception' => \$result['exception']",
      $contents,
      'The base must pass the exception object to the logger for stack traces'
    );
  }

  // -------------------------------------------------------------------
  // 4. Error HTTP responses do NOT contain the exception message.
  // -------------------------------------------------------------------

  public function testErrorResponseDoesNotLeakExceptionMessage(): void {
    $contents = $this->baseSource();

    // The error responses use $result['error'] (a static string from the
    // handler), never $e->getMessage() or $result['exception']->getMessage().
    $this->assertStringContainsString(
      "\$response = ['error' => \$result['error']];",
      $contents,
      'The base must use the handler error message in HTTP responses, not raw exceptions'
    );
    $this->assertStringNotContainsString(
      "\$result['exception']->getMessage()] ,",
      $contents
    );
  }

}
