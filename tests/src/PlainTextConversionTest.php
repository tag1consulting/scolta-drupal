<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Component\Render\PlainTextOutput;
use PHPUnit\Framework\TestCase;

/**
 * Tests that content gathering and export use PlainTextOutput instead of strip_tags().
 */
class PlainTextConversionTest extends TestCase {

  private string $gathererFile;
  private string $exporterFile;

  protected function setUp(): void {
    $root = dirname(__DIR__, 2);
    $this->gathererFile = $root . '/src/Service/ScoltaContentGatherer.php';
    $this->exporterFile = $root . '/src/Service/PagefindExporter.php';
  }

  // -------------------------------------------------------------------
  // Source code: strip_tags() must not appear in production paths.
  // -------------------------------------------------------------------

  public function testGathererDoesNotUseStripTags(): void {
    $contents = file_get_contents($this->gathererFile);
    // Allow strip_tags only on phpcs:ignore lines or in comments.
    $violations = $this->findRawCalls($contents, 'strip_tags(');
    $this->assertEmpty($violations,
      "ScoltaContentGatherer must not use strip_tags(); use PlainTextOutput::renderFromHtml().\nViolations:\n" . implode("\n", $violations));
  }

  public function testExporterDoesNotUseStripTags(): void {
    $contents = file_get_contents($this->exporterFile);
    $violations = $this->findRawCalls($contents, 'strip_tags(');
    $this->assertEmpty($violations,
      "PagefindExporter must not use strip_tags(); use PlainTextOutput::renderFromHtml().\nViolations:\n" . implode("\n", $violations));
  }

  public function testGathererUsesPlainTextOutput(): void {
    $contents = file_get_contents($this->gathererFile);
    $this->assertStringContainsString(
      'PlainTextOutput::renderFromHtml(',
      $contents,
      'ScoltaContentGatherer must use PlainTextOutput::renderFromHtml() for HTML-to-text conversion'
    );
  }

  public function testExporterUsesPlainTextOutput(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString(
      'PlainTextOutput::renderFromHtml(',
      $contents,
      'PagefindExporter must use PlainTextOutput::renderFromHtml() for empty-content check'
    );
  }

  public function testGathererImportsPlainTextOutputClass(): void {
    $contents = file_get_contents($this->gathererFile);
    $this->assertStringContainsString(
      'use Drupal\Component\Render\PlainTextOutput;',
      $contents,
      'ScoltaContentGatherer must import Drupal\Component\Render\PlainTextOutput'
    );
  }

  public function testExporterImportsPlainTextOutputClass(): void {
    $contents = file_get_contents($this->exporterFile);
    $this->assertStringContainsString(
      'use Drupal\Component\Render\PlainTextOutput;',
      $contents,
      'PagefindExporter must import Drupal\Component\Render\PlainTextOutput'
    );
  }

  // -------------------------------------------------------------------
  // PlainTextOutput::renderFromHtml() behavior.
  // -------------------------------------------------------------------

  public function testPlainTextOutputDecodesHtmlEntities(): void {
    $input = '<p>AT&amp;T &lt;strong&gt; example</p>';
    $result = PlainTextOutput::renderFromHtml($input);
    $this->assertStringContainsString('AT&T', $result, 'renderFromHtml() must decode &amp; entities');
    $this->assertStringNotContainsString('&amp;', $result, 'renderFromHtml() must not leave &amp; encoded');
  }

  public function testPlainTextOutputStripsHtmlTags(): void {
    $input = '<div class="body"><p>Hello <strong>world</strong></p></div>';
    $result = PlainTextOutput::renderFromHtml($input);
    $this->assertStringNotContainsString('<', $result, 'renderFromHtml() must strip all HTML tags');
    $this->assertStringContainsString('Hello', $result, 'renderFromHtml() must preserve text content');
    $this->assertStringContainsString('world', $result, 'renderFromHtml() must preserve text content');
  }

  public function testPlainTextOutputHandlesNestedTagsAndAttributes(): void {
    $input = '<article data-id="42"><h1 class="title">My Title</h1><p style="color:red">Content here.</p></article>';
    $result = PlainTextOutput::renderFromHtml($input);
    $this->assertStringNotContainsString('<', $result);
    $this->assertStringContainsString('My Title', $result);
    $this->assertStringContainsString('Content here.', $result);
  }

  public function testPlainTextOutputHandlesMalformedHtml(): void {
    $input = '<p>Unclosed tag <b>bold text <em>italic';
    $result = PlainTextOutput::renderFromHtml($input);
    $this->assertStringNotContainsString('<', $result, 'renderFromHtml() must handle malformed HTML');
    $this->assertStringContainsString('bold text', $result);
  }

  public function testEmptyHtmlProducesEmptyCheck(): void {
    // Whitespace-only HTML should produce empty/whitespace output — the
    // empty check in PagefindExporter must still correctly skip the item.
    $input = '<div>   <p>  </p>  </div>';
    $result = PlainTextOutput::renderFromHtml($input);
    $this->assertEmpty(trim($result), 'Whitespace-only HTML must produce empty plain text');
  }

  public function testEmptyStringProducesEmpty(): void {
    $result = PlainTextOutput::renderFromHtml('');
    $this->assertEmpty($result);
  }

  public function testStringCastBeforeConversion(): void {
    // Verify that casting to (string) before renderFromHtml() is safe.
    // This mirrors the production code pattern for FilteredMarkup objects.
    $html = '<p>Hello &amp; world</p>';
    $result = PlainTextOutput::renderFromHtml((string) $html);
    $this->assertStringContainsString('Hello & world', $result);
  }

  // -------------------------------------------------------------------
  // PlainTextOutput vs strip_tags() difference: entity decoding.
  // -------------------------------------------------------------------

  public function testPlainTextOutputDecodesEntitiesUnlikeStripTags(): void {
    $input = '<p>AT&amp;T</p>';

    $stripTagsResult = strip_tags($input);
    $plainTextResult = PlainTextOutput::renderFromHtml($input);

    // strip_tags() leaves &amp; encoded, PlainTextOutput decodes it.
    $this->assertEquals('AT&amp;T', $stripTagsResult,
      'strip_tags() leaves HTML entities encoded (this is why we replaced it)');
    $this->assertEquals('AT&T', $plainTextResult,
      'PlainTextOutput::renderFromHtml() decodes HTML entities to plain text');
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  private function findRawCalls(string $source, string $func): array {
    $lines = explode("\n", $source);
    $violations = [];
    foreach ($lines as $num => $line) {
      if (str_contains($line, $func)) {
        // Skip lines with phpcs:ignore or in comments.
        if (str_contains($line, 'phpcs:ignore') || preg_match('/^\s*\/\//', $line) || preg_match('/^\s*\*/', $line)) {
          continue;
        }
        $violations[] = ($num + 1) . ': ' . trim($line);
      }
    }
    return $violations;
  }

}
