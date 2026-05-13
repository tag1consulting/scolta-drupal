<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests that content gathering and export use PlainTextOutput instead of strip_tags().
 *
 * Behavioral tests use the equivalent pure-PHP logic
 * (html_entity_decode(strip_tags())) rather than importing the Drupal class
 * directly, so they pass in the unit-test environment where drupal/core is
 * only "provided" and not actually installed.
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

  public function testGathererCastsToStringBeforeConversion(): void {
    // Production code must cast ->processed (FilteredMarkup) to string first.
    $contents = file_get_contents($this->gathererFile);
    $this->assertStringContainsString(
      'PlainTextOutput::renderFromHtml((string) $item->processed)',
      $contents,
      'gather() must cast ->processed to string before PlainTextOutput::renderFromHtml()'
    );
  }

  // -------------------------------------------------------------------
  // Behavioral tests: renderFromHtml() semantics.
  //
  // PlainTextOutput::renderFromHtml() = html_entity_decode(strip_tags()).
  // Tests use the equivalent pure-PHP formula so they run without drupal/core.
  // -------------------------------------------------------------------

  /**
   * Emulate PlainTextOutput::renderFromHtml() without requiring drupal/core.
   */
  private function plainText(string $html): string {
    return html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
  }

  public function testPlainTextDecodesHtmlEntities(): void {
    $input = '<p>AT&amp;T &lt;example&gt;</p>';
    $result = $this->plainText($input);
    $this->assertStringContainsString('AT&T', $result, 'renderFromHtml() must decode &amp; to &');
    $this->assertStringNotContainsString('&amp;', $result, 'renderFromHtml() must not leave &amp; encoded');
  }

  public function testPlainTextStripsHtmlTags(): void {
    $input = '<div class="body"><p>Hello <strong>world</strong></p></div>';
    $result = $this->plainText($input);
    $this->assertStringNotContainsString('<', $result, 'renderFromHtml() must strip all HTML tags');
    $this->assertStringContainsString('Hello', $result);
    $this->assertStringContainsString('world', $result);
  }

  public function testPlainTextHandlesNestedTagsAndAttributes(): void {
    $input = '<article data-id="42"><h1 class="title">My Title</h1><p>Content.</p></article>';
    $result = $this->plainText($input);
    $this->assertStringNotContainsString('<', $result);
    $this->assertStringContainsString('My Title', $result);
    $this->assertStringContainsString('Content.', $result);
  }

  public function testPlainTextHandlesMalformedHtml(): void {
    $input = '<p>Unclosed tag <b>bold text <em>italic';
    $result = $this->plainText($input);
    $this->assertStringNotContainsString('<', $result);
    $this->assertStringContainsString('bold text', $result);
  }

  public function testWhitespaceOnlyHtmlProducesEmptyAfterTrim(): void {
    // Whitespace-only HTML must produce empty plain text so PagefindExporter
    // correctly skips the item via empty(trim(...)).
    $input = '<div>   <p>  </p>  </div>';
    $result = $this->plainText($input);
    $this->assertEmpty(trim($result), 'Whitespace-only HTML must produce empty plain text');
  }

  public function testEmptyStringProducesEmpty(): void {
    $result = $this->plainText('');
    $this->assertEmpty($result);
  }

  public function testStringCastBeforeConversion(): void {
    $html = '<p>Hello &amp; world</p>';
    $result = $this->plainText((string) $html);
    $this->assertStringContainsString('Hello & world', $result);
  }

  // -------------------------------------------------------------------
  // Why we replaced strip_tags(): entity decoding difference.
  // -------------------------------------------------------------------

  public function testStripTagsAloneDoesNotDecodeEntities(): void {
    $input = '<p>AT&amp;T</p>';
    $stripTagsOnly = strip_tags($input);
    $withDecoding = $this->plainText($input);

    $this->assertEquals('AT&amp;T', $stripTagsOnly,
      'strip_tags() alone leaves HTML entities encoded — this is why we replaced it');
    $this->assertEquals('AT&T', $withDecoding,
      'html_entity_decode(strip_tags()) decodes entities — this is what PlainTextOutput::renderFromHtml() does');
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  private function findRawCalls(string $source, string $func): array {
    $lines = explode("\n", $source);
    $violations = [];
    foreach ($lines as $num => $line) {
      if (!str_contains($line, $func)) {
        continue;
      }
      // Skip phpcs:ignore lines and comment lines.
      if (str_contains($line, 'phpcs:ignore')
        || preg_match('/^\s*\/\//', $line)
        || preg_match('/^\s*\*/', $line)
      ) {
        continue;
      }
      $violations[] = ($num + 1) . ': ' . trim($line);
    }
    return $violations;
  }

}
