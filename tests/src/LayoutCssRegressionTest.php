<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the Drupal full-width layout bug (0.2.4).
 *
 * Root cause: packages/scolta-drupal/css/scolta.css was a copy of the
 * scolta-php CSS that was not updated when scolta-php switched
 * .scolta-layout to default to grid-template-columns: 1fr with a
 * .has-filters modifier for the two-column case. The Drupal CSS had
 * the two-column layout as the permanent default, making the 220px
 * filter sidebar always occupy space — even when empty — and squeezing
 * all search results into the narrow right column.
 */
class LayoutCssRegressionTest extends TestCase {

  private string $css;

  protected function setUp(): void {
    $this->css = file_get_contents(dirname(__DIR__, 2) . '/css/scolta.css');
  }

  /**
   * .scolta-layout default must be single-column (no sidebar).
   *
   * The two-column layout must only activate via .has-filters, which the
   * JS adds when multiple sites are present. Without this, the filter
   * column is permanently visible (even empty), occupying ~220px and
   * squeezing results into a narrow right column.
   */
  public function testLayoutDefaultIsSingleColumn(): void {
    // Extract the base .scolta-layout rule (not .has-filters variant).
    preg_match_all(
      '/\.scolta-layout\s*\{([^}]+)\}/',
      $this->css,
      $matches
    );

    $base_rule = '';
    foreach ($matches[0] as $rule) {
      if (strpos($rule, 'has-filters') === false) {
        $base_rule = $rule;
        break;
      }
    }

    $this->assertNotEmpty($base_rule, 'Could not locate base .scolta-layout rule');
    $this->assertStringContainsString(
      'grid-template-columns: 1fr',
      $base_rule,
      '.scolta-layout default must be single-column (1fr) — two-column layout belongs on .has-filters only'
    );
    $this->assertStringNotContainsString(
      '220px',
      $base_rule,
      '.scolta-layout base rule must not hard-code 220px — that belongs on .scolta-layout.has-filters'
    );
  }

  /**
   * .scolta-layout.has-filters must enable the two-column layout.
   */
  public function testHasFiltersEnablesTwoColumnLayout(): void {
    $this->assertMatchesRegularExpression(
      '/\.scolta-layout\.has-filters\s*\{[^}]*220px[^}]*\}/',
      $this->css,
      '.scolta-layout.has-filters must define the 220px sidebar column'
    );
  }

  /**
   * Empty filter sidebar must be hidden via CSS.
   *
   * Without this rule an empty <aside class="scolta-filters"> still
   * occupies a grid track even though it has no visible content.
   */
  public function testEmptyFiltersSidebarIsHidden(): void {
    $this->assertStringContainsString(
      '.scolta-filters:empty',
      $this->css,
      '.scolta-filters:empty { display: none } must be present to hide the sidebar when no filters are rendered'
    );
    preg_match('/\.scolta-filters:empty\s*\{([^}]+)\}/', $this->css, $m);
    $this->assertStringContainsString(
      'display: none',
      $m[1] ?? '',
      '.scolta-filters:empty must set display: none'
    );
  }

  /**
   * Responsive override must target .has-filters, not the base rule.
   */
  public function testResponsiveOverrideTargetsHasFilters(): void {
    preg_match('/@media[^{]+max-width[^{]+700px[^{]*\{([^@]+)\}/s', $this->css, $m);
    $media_block = $m[1] ?? '';
    $this->assertStringContainsString(
      '.scolta-layout.has-filters',
      $media_block,
      'Responsive 700px breakpoint must target .scolta-layout.has-filters, not the base rule'
    );
  }
}
