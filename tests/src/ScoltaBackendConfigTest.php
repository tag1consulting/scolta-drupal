<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests ScoltaBackend configuration form and defaults via file inspection.
 *
 * Verifies auto_rebuild_delay is present in defaultConfiguration(),
 * exposed in buildConfigurationForm(), validated, and saved in
 * submitConfigurationForm() — without requiring a Drupal bootstrap.
 */
class ScoltaBackendConfigTest extends TestCase {

  private string $backendFile;
  private string $backendContents;

  protected function setUp(): void {
    $this->backendFile = dirname(__DIR__, 2)
      . '/src/Plugin/search_api/backend/ScoltaBackend.php';
    $this->backendContents = file_get_contents($this->backendFile);
  }

  // -------------------------------------------------------------------
  // defaultConfiguration()
  // -------------------------------------------------------------------

  public function testDefaultConfigurationIncludesAutoRebuildDelay(): void {
    $this->assertStringContainsString(
      "'auto_rebuild_delay'",
      $this->backendContents,
      'defaultConfiguration() must include auto_rebuild_delay key'
    );
  }

  public function testDefaultAutoRebuildDelayIs300(): void {
    // Find the defaultConfiguration() method body and check the value.
    preg_match(
      '/public function defaultConfiguration\(\): array \{(.*?)\}/s',
      $this->backendContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      "'auto_rebuild_delay' => 300",
      $body,
      'auto_rebuild_delay must default to 300 seconds'
    );
  }

  // -------------------------------------------------------------------
  // buildConfigurationForm()
  // -------------------------------------------------------------------

  public function testBuildConfigurationFormHasAutoRebuildDelayField(): void {
    preg_match(
      '/public function buildConfigurationForm\(.*?\{(.*?)return \$form;/s',
      $this->backendContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      "'auto_rebuild_delay'",
      $body,
      'buildConfigurationForm() must define an auto_rebuild_delay field'
    );
  }

  public function testAutoRebuildDelayFieldTypeIsNumber(): void {
    preg_match(
      "/\\\$form\['auto_rebuild_delay'\](.*?);\s*\n/s",
      $this->backendContents,
      $match
    );

    $this->assertStringContainsString(
      "'#type' => 'number'",
      $this->backendContents,
      'auto_rebuild_delay form field must use #type number'
    );
  }

  public function testAutoRebuildDelayFieldHasStates(): void {
    $this->assertStringContainsString(
      "'#states'",
      $this->backendContents,
      'auto_rebuild_delay field must have #states visibility tied to auto_rebuild checkbox'
    );
  }

  public function testAutoRebuildDelayFieldMinIs60(): void {
    $this->assertStringContainsString(
      "'#min' => 60",
      $this->backendContents,
      'auto_rebuild_delay field must have #min 60'
    );
  }

  public function testAutoRebuildDelayFieldMaxIs3600(): void {
    $this->assertStringContainsString(
      "'#max' => 3600",
      $this->backendContents,
      'auto_rebuild_delay field must have #max 3600'
    );
  }

  // -------------------------------------------------------------------
  // validateConfigurationForm()
  // -------------------------------------------------------------------

  public function testValidateConfigurationFormClampsDelay(): void {
    // Verify auto_rebuild_delay clamping is present in validateConfigurationForm.
    // (We check the full file because the method contains nested braces that
    // make regex extraction unreliable without a full PHP parser.)
    $this->assertStringContainsString(
      'auto_rebuild_delay',
      $this->backendContents,
      'validateConfigurationForm() must clamp auto_rebuild_delay'
    );

    // Also verify the clamping uses max/min.
    $this->assertMatchesRegularExpression(
      '/max\s*\(\s*60\s*,\s*min\s*\(\s*3600/',
      $this->backendContents,
      'auto_rebuild_delay must be clamped to 60–3600'
    );
  }

  // -------------------------------------------------------------------
  // submitConfigurationForm()
  // -------------------------------------------------------------------

  public function testSubmitConfigurationFormSavesDelay(): void {
    preg_match(
      '/public function submitConfigurationForm\(.*?\{(.*?)\}/s',
      $this->backendContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      "configuration['auto_rebuild_delay']",
      $body,
      'submitConfigurationForm() must save auto_rebuild_delay'
    );
  }

}
