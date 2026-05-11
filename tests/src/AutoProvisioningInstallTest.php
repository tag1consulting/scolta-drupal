<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that scolta.install wires up Amazee.ai auto-provisioning.
 *
 * These are file-inspection tests — no Drupal bootstrap required.
 */
class AutoProvisioningInstallTest extends TestCase {

  private string $installFile;
  private string $installSource;

  protected function setUp(): void {
    $this->installFile = dirname(__DIR__, 2) . '/scolta.install';
    $this->installSource = file_get_contents($this->installFile);
  }

  // -------------------------------------------------------------------
  // Install hook calls the provisioning helper.
  // -------------------------------------------------------------------

  public function testScoltaInstallCallsAutoProvisioner(): void {
    $this->assertStringContainsString(
      '_scolta_auto_provision_amazee()',
      $this->installSource,
      'scolta_install() must call _scolta_auto_provision_amazee()'
    );
  }

  public function testAutoProvisionHelperExists(): void {
    $this->assertStringContainsString(
      'function _scolta_auto_provision_amazee()',
      $this->installSource,
      '_scolta_auto_provision_amazee() helper function must exist'
    );
  }

  public function testAutoProvisionHelperUsesAutoProvisionerClass(): void {
    $this->assertStringContainsString(
      'AutoProvisioner::ensureAiAvailable(',
      $this->installSource,
      '_scolta_auto_provision_amazee() must call AutoProvisioner::ensureAiAvailable()'
    );
  }

  public function testAutoProvisionHelperUsesConfigStorage(): void {
    $this->assertStringContainsString(
      "'scolta.amazee_config_storage'",
      $this->installSource,
      '_scolta_auto_provision_amazee() must fetch the scolta.amazee_config_storage service'
    );
  }

  // -------------------------------------------------------------------
  // Explicit API key guard.
  // -------------------------------------------------------------------

  public function testHasExplicitApiKeyHelperExists(): void {
    $this->assertStringContainsString(
      'function _scolta_has_explicit_api_key()',
      $this->installSource,
      '_scolta_has_explicit_api_key() helper function must exist'
    );
  }

  public function testExplicitApiKeyChecksCombinedSources(): void {
    $this->assertStringContainsString(
      'SCOLTA_API_KEY',
      $this->installSource,
      '_scolta_has_explicit_api_key() must check SCOLTA_API_KEY env var'
    );
    $this->assertStringContainsString(
      'scolta.api_key',
      $this->installSource,
      '_scolta_has_explicit_api_key() must check settings.php scolta.api_key'
    );
  }

  public function testHasExplicitApiKeyChecksDrupalConfig(): void {
    $this->assertStringContainsString(
      "config('scolta.settings')",
      $this->installSource,
      '_scolta_has_explicit_api_key() must check Drupal config for ai_api_key'
    );
  }

  public function testAutoProvisionPassesExplicitApiKeyFlag(): void {
    $this->assertStringContainsString(
      'hasExplicitApiKey: _scolta_has_explicit_api_key()',
      $this->installSource,
      '_scolta_auto_provision_amazee() must pass _scolta_has_explicit_api_key() as the flag'
    );
  }

  // -------------------------------------------------------------------
  // Auto-provisioner must NOT overwrite user model settings.
  // -------------------------------------------------------------------

  public function testAutoProvisionDoesNotPassOnModelsResolved(): void {
    // The onModelsResolved callback must not be passed to ensureAiAvailable()
    // in the install hook — it would silently overwrite user model settings
    // with Amazee-resolved defaults (Haiku for expansion query).
    $this->assertStringNotContainsString(
      'onModelsResolved:',
      $this->installSource,
      '_scolta_auto_provision_amazee() must not pass an onModelsResolved callback'
    );
  }

}
