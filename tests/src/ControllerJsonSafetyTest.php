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
  // 5. Each controller's catch block includes 'exception' => $e.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testExceptionObjectPreservedInLog(string $className, string $file): void {
    $contents = file_get_contents($file);
    $this->assertStringContainsString(
      "'exception' => \$e",
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

    // Find the catch (\Exception $e) block and its JsonResponse.
    // The 503 error responses should use a static string, not $e->getMessage().
    if (preg_match('/catch\s*\(\s*\\\\Exception\s+\$e\s*\)\s*\{(.*?)\}/s', $contents, $m)) {
      $catchBlock = $m[1];
      // The JsonResponse in the catch block should not contain $e->getMessage().
      $this->assertStringNotContainsString(
        '$e->getMessage()',
        $this->extractJsonResponseFromCatch($catchBlock),
        "{$className} must not leak exception message in HTTP response"
      );
    }
    else {
      $this->fail("{$className} must have a catch (\\Exception \$e) block");
    }
  }

  /**
   * Extract the JsonResponse line(s) from a catch block.
   *
   * This isolates the return statement so we can verify the exception
   * message is only passed to the logger, not the HTTP response.
   */
  private function extractJsonResponseFromCatch(string $catchBlock): string {
    if (preg_match('/return\s+new\s+JsonResponse\([^;]+;/s', $catchBlock, $m)) {
      return $m[0];
    }
    return '';
  }

}
