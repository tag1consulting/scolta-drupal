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
    // The guard reads the shared resolution rather than a source string.
    // `amazeeCredentialsStored` rather than `source === none` is deliberate: a
    // site whose provider is drupal_ai resolves to none while holding stored
    // credentials, and must not provision a trial on top of them.
    $this->assertStringContainsString(
      '$resolved = $this->resolveApiKey();',
      $this->serviceSource,
      'createClient() must take its answer from resolveApiKey()'
    );
    $this->assertStringContainsString(
      '!$resolved->isConfigured() && !$resolved->amazeeCredentialsStored',
      $this->serviceSource,
      'createClient() must provision only when nothing is configured and nothing is stored'
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
      '$this->amazeeConfigStorage',
      $this->serviceSource,
      'createClient() must use the injected Amazee config storage'
    );
    $this->assertStringNotContainsString(
      "\Drupal::service('scolta.amazee_config_storage')",
      $this->serviceSource,
      'The Amazee config storage must be constructor-injected, not fetched statically'
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

  /**
   * Resolved models are persisted, and only to the gateway-scoped keys.
   *
   * Updated for scolta-drupal#187: the callback used to write ai_model /
   * ai_expansion_model, the keys an administrator uses to name a
   * provider-native model. Those hold names the Amazee gateway never returns,
   * so persisting a gateway alias there clobbered an explicit choice and broke
   * AI outright once the effective provider changed.
   */
  public function testCreateClientPersistsResolvedModelsToTheGatewayKeys(): void {
    $callback = $this->persistCallbackBody();

    $this->assertStringContainsString(
      "\$config->set('amazee_model', \$aiModel)",
      $callback,
      'The onModelsResolved callback must persist the resolved model to amazee_model'
    );
    $this->assertStringContainsString(
      "\$config->set('amazee_expansion_model', \$aiExpansionModel)",
      $callback,
      'The onModelsResolved callback must persist the resolved expansion model to amazee_expansion_model'
    );
    $this->assertStringNotContainsString(
      "set('ai_model'",
      $callback,
      'The onModelsResolved callback must never write the operator-facing ai_model'
    );
    $this->assertStringNotContainsString(
      "set('ai_expansion_model'",
      $callback,
      'The onModelsResolved callback must never write the operator-facing ai_expansion_model'
    );
  }

  public function testCreateClientWiresThePersistCallback(): void {
    $this->assertStringContainsString(
      'onModelsResolved: $this->persistResolvedAmazeeModels(...)',
      $this->serviceSource,
      'createClient() must hand AutoProvisioner the persistResolvedAmazeeModels() callback'
    );
  }

  /**
   * Nothing in the service may write a gateway alias to the operator keys.
   */
  public function testServiceNeverWritesTheOperatorFacingModelKeys(): void {
    $this->assertDoesNotMatchRegularExpression(
      "/getEditable\('scolta\.settings'\)(?:.|\n)*?->set\('ai_(?:expansion_)?model'/",
      $this->serviceSource,
      'ScoltaAiService must not persist any model into the operator-facing keys'
    );
  }

  /**
   * The body of persistResolvedAmazeeModels(), the onModelsResolved callback.
   */
  private function persistCallbackBody(): string {
    $start = strpos($this->serviceSource, 'protected function persistResolvedAmazeeModels(');
    $this->assertNotFalse(
      $start,
      'ScoltaAiService must define persistResolvedAmazeeModels() as the onModelsResolved callback'
    );
    $end = strpos($this->serviceSource, "\n  }", $start);
    $this->assertNotFalse($end, 'persistResolvedAmazeeModels() must have a closing brace');

    return substr($this->serviceSource, $start, $end - $start);
  }

}
