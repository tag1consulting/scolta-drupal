<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that README.md documents shared-hosting and large-corpus patterns.
 *
 * These file-inspection tests prevent documentation regressions — if a future
 * edit removes the shared-hosting section or the resume/restart guidance, the
 * test suite catches it before the PR merges.
 */
class SharedHostingDocTest extends TestCase {

  private string $readme;

  protected function setUp(): void {
    $path = dirname(__DIR__, 2) . '/README.md';
    $this->readme = file_get_contents($path);
  }

  // -------------------------------------------------------------------
  // Shared hosting section exists.
  // -------------------------------------------------------------------

  public function testReadmeHasSharedHostingSection(): void {
    $this->assertStringContainsString(
      'Large Corpora and Shared Hosting',
      $this->readme,
      'README must have a "Large Corpora and Shared Hosting" section'
    );
  }

  public function testReadmeRecommendsScoltaBuildNotSearchApiIndex(): void {
    $this->assertStringContainsString(
      'drush scolta:build` for initial and full index builds',
      $this->readme,
      'README must recommend drush scolta:build (not drush search-api:index) for large builds'
    );
  }

  // -------------------------------------------------------------------
  // SSH disconnect resilience is documented.
  // -------------------------------------------------------------------

  public function testReadmeDocumentsNohup(): void {
    $this->assertStringContainsString(
      'nohup',
      $this->readme,
      'README must document nohup for SSH disconnect resilience'
    );
  }

  public function testReadmeDocumentsScreen(): void {
    $this->assertStringContainsString(
      'screen',
      $this->readme,
      'README must document screen for SSH disconnect resilience'
    );
  }

  public function testReadmeDocumentsTmux(): void {
    $this->assertStringContainsString(
      'tmux',
      $this->readme,
      'README must document tmux for SSH disconnect resilience'
    );
  }

  // -------------------------------------------------------------------
  // Resume / restart flags are documented.
  // -------------------------------------------------------------------

  public function testReadmeDocumentsResumeFlag(): void {
    $this->assertStringContainsString(
      '--resume',
      $this->readme,
      'README must document --resume flag for recovering interrupted builds'
    );
  }

  public function testReadmeDocumentsRestartFlag(): void {
    $this->assertStringContainsString(
      '--restart',
      $this->readme,
      'README must document --restart flag for discarding interrupted build state'
    );
  }

  public function testReadmeExplainsWhenToUseResumeVsRestart(): void {
    // Both flags must appear in proximity to guidance about when to use each.
    $this->assertStringContainsString(
      'Resuming an interrupted build',
      $this->readme,
      'README must explain when to use --resume vs --restart'
    );
  }

  // -------------------------------------------------------------------
  // scolta:finalize is documented.
  // -------------------------------------------------------------------

  public function testReadmeDocumentsFinalizeCommand(): void {
    $this->assertStringContainsString(
      'drush scolta:finalize',
      $this->readme,
      'README must document drush scolta:finalize for deferred merge on large corpora'
    );
  }

  // -------------------------------------------------------------------
  // Drush commands table covers all build flags.
  // -------------------------------------------------------------------

  public function testDrushCommandsTableIncludesResume(): void {
    $this->assertStringContainsString(
      '--resume',
      $this->readme,
      'Drush commands section must list --resume flag'
    );
  }

  public function testDrushCommandsTableIncludesRestart(): void {
    $this->assertStringContainsString(
      '--restart',
      $this->readme,
      'Drush commands section must list --restart flag'
    );
  }

  public function testDrushCommandsTableIncludesForce(): void {
    $this->assertStringContainsString(
      '--force',
      $this->readme,
      'Drush commands section must list --force flag'
    );
  }

  public function testDrushCommandsTableIncludesChunkSize(): void {
    $this->assertStringContainsString(
      '--chunk-size',
      $this->readme,
      'Drush commands section must list --chunk-size flag'
    );
  }

  public function testDrushCommandsTableIncludesFinalize(): void {
    $this->assertStringContainsString(
      'scolta:finalize',
      $this->readme,
      'Drush commands section must list scolta:finalize'
    );
  }

  // -------------------------------------------------------------------
  // "No search results" tip no longer recommends search-api:index.
  // -------------------------------------------------------------------

  public function testNoSearchResultsTipDoesNotRecommendSearchApiIndex(): void {
    // The old tip said `drush search-api:index && drush scolta:build` which
    // is dangerous on large corpora — search-api:index batches through
    // Search API's pipeline and can exhaust shared-host resource limits.
    // The tip should now point to drush scolta:build only.
    $noResultsSection = '';
    if (preg_match('/"No search results"(.*?)###/s', $this->readme, $m)) {
      $noResultsSection = $m[1];
    }
    $this->assertNotEmpty($noResultsSection, 'Could not locate "No search results" section');
    $this->assertStringNotContainsString(
      'drush search-api:index && drush scolta:build',
      $noResultsSection,
      '"No search results" tip must not recommend drush search-api:index for a full rebuild'
    );
  }

  public function testNoSearchResultsTipPointsToScoltaBuild(): void {
    $noResultsSection = '';
    if (preg_match('/"No search results"(.*?)###/s', $this->readme, $m)) {
      $noResultsSection = $m[1];
    }
    $this->assertNotEmpty($noResultsSection, 'Could not locate "No search results" section');
    $this->assertStringContainsString(
      'drush scolta:build',
      $noResultsSection,
      '"No search results" tip must recommend drush scolta:build for a full rebuild'
    );
  }

}
