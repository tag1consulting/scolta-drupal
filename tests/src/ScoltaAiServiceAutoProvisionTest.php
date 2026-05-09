<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the auto-provisioning fallback wired into ScoltaAiService.
 *
 * File-inspection tests — no Drupal bootstrap required.
 */
class ScoltaAiServiceAutoProvisionTest extends TestCase {

  private string $serviceFile;
  private string $serviceSource;

  protected function setUp(): void {
    $this->serviceFile = dirname(__DIR__, 2) . '/src/Service/ScoltaAiService.php';
    $this->serviceSource = file_get_contents($this->serviceFile);
  }

  public function testImportsAutoProvisioner(): void {
    $this->assertStringContainsString(
      'use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner',
      $this->serviceSource,
      'ScoltaAiService must import AutoProvisioner'
    );
  }

  public function testOverridesCreateClient(): void {
    $this->assertStringContainsString(
      'protected function createClient(): AiClient',
      $this->serviceSource,
      'ScoltaAiService must override createClient()'
    );
  }

  public function testCreateClientChecksApiKeySource(): void {
    $this->assertStringContainsString(
      "getApiKeySource() === 'none'",
      $this->serviceSource,
      'createClient() must guard on getApiKeySource() === \'none\''
    );
  }

  public function testCreateClientCallsAutoProvisioner(): void {
    $this->assertStringContainsString(
      'AutoProvisioner::ensureAiAvailable(',
      $this->serviceSource,
      'createClient() must call AutoProvisioner::ensureAiAvailable()'
    );
  }

  public function testCreateClientUsesConfigStorage(): void {
    $this->assertStringContainsString(
      "'scolta.amazee_config_storage'",
      $this->serviceSource,
      'createClient() must fetch scolta.amazee_config_storage service'
    );
  }

  public function testCreateClientRebuildsFreshConfigAfterProvisioning(): void {
    $this->assertStringContainsString(
      '$this->buildConfig()->toAiClientConfig()',
      $this->serviceSource,
      'createClient() must rebuild config after provisioning to pick up new credentials'
    );
  }

  public function testBuildConfigIsProtected(): void {
    $this->assertMatchesRegularExpression(
      '/protected function buildConfig\(\)/',
      $this->serviceSource,
      'buildConfig() must be protected so createClient() can call it for fresh credentials'
    );
  }

  public function testCreateClientPersistsResolvedModels(): void {
    $this->assertStringContainsString(
      "'ai_model'",
      $this->serviceSource,
      'createClient() onModelsResolved callback must persist ai_model'
    );
    $this->assertStringContainsString(
      "'ai_expansion_model'",
      $this->serviceSource,
      'createClient() onModelsResolved callback must persist ai_expansion_model'
    );
  }

}
