<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for controller security and correctness fixes.
 *
 * Verifies via source analysis (no Drupal bootstrap required):
 *   - json_decode uses JSON_THROW_ON_ERROR with a \JsonException catch
 *   - Sensitive data is not logged (raw AI responses, print_r output)
 *   - Exception stack traces are preserved in logger calls
 *   - Exception details are not leaked in HTTP error responses
 */
class ControllerJsonSafetyTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  /**
   * Data provider for all three controllers.
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

  // -------------------------------------------------------------------
  // 1. json_decode uses JSON_THROW_ON_ERROR in the handle() method.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testJsonDecodeUsesThrowOnError(string $className, string $file): void {
    $contents = file_get_contents($file);
    $this->assertStringContainsString(
      'JSON_THROW_ON_ERROR',
      $contents,
      "{$className}::handle() must use JSON_THROW_ON_ERROR in json_decode"
    );
  }

  // -------------------------------------------------------------------
  // 2. Each controller catches \JsonException and returns 400.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testJsonExceptionReturnsBadRequest(string $className, string $file): void {
    $contents = file_get_contents($file);
    $this->assertStringContainsString(
      'catch (\JsonException $e)',
      $contents,
      "{$className}::handle() must catch \\JsonException"
    );
    $this->assertStringContainsString(
      "'error' => 'Malformed JSON: '",
      $contents,
      "{$className} must return a Malformed JSON error message"
    );
    // Verify 400 status code is used for malformed JSON.
    $this->assertMatchesRegularExpression(
      "/Malformed JSON.*400/s",
      $contents,
      "{$className} must return HTTP 400 for malformed JSON"
    );
  }

  // -------------------------------------------------------------------
  // 3. ExpandQueryController does NOT log raw AI responses.
  // -------------------------------------------------------------------

  public function testExpandDoesNotLogRawResponse(): void {
    $contents = file_get_contents(
      $this->moduleRoot . '/src/Controller/ExpandQueryController.php'
    );
    $this->assertStringNotContainsString(
      'Expand raw response',
      $contents,
      'ExpandQueryController must not log raw AI responses (sensitive data leak)'
    );
  }

  // -------------------------------------------------------------------
  // 4. ExpandQueryController does NOT use print_r in log messages.
  // -------------------------------------------------------------------

  public function testExpandDoesNotUsePrintR(): void {
    $contents = file_get_contents(
      $this->moduleRoot . '/src/Controller/ExpandQueryController.php'
    );
    $this->assertStringNotContainsString(
      'print_r',
      $contents,
      'ExpandQueryController must not use print_r (sensitive data in logs)'
    );
  }

  // -------------------------------------------------------------------
  // 5. Each controller's error-logging block includes the exception object.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testExceptionObjectPreservedInLog(string $className, string $file): void {
    $contents = file_get_contents($file);
    $this->assertStringContainsString(
      "'exception' => \$result['exception']",
      $contents,
      "{$className} must pass the exception object to the logger for stack traces"
    );
  }

  // -------------------------------------------------------------------
  // 6. Error HTTP responses do NOT contain the exception message.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testErrorResponseDoesNotLeakExceptionMessage(string $className, string $file): void {
    $contents = file_get_contents($file);

    // The error responses use $result['error'] (a static string from the handler),
    // never $e->getMessage() or $result['exception']->getMessage().
    $this->assertStringContainsString(
      "['error' => \$result['error']]",
      $contents,
      "{$className} must use handler error message in HTTP response, not raw exception"
    );
  }

}
