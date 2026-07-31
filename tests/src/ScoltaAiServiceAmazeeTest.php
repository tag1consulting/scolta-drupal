<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Amazee.ai integration in ScoltaAiService without a bootstrap.
 *
 * Reads the source file and confirms the structural changes are present.
 */
class ScoltaAiServiceAmazeeTest extends TestCase {

  private string $serviceFile;

  protected function setUp(): void {
    $this->serviceFile = dirname(__DIR__, 2) . '/src/Service/ScoltaAiService.php';
  }

  public function testImportsTheAmazeeCredentialStoreInterface(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString(
      'use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface',
      $contents,
      'Amazee credentials reach this service through the store that decrypts them'
    );
  }

  public function testTheServiceNeverReadsTheCredentialStateItself(): void {
    // The token is encrypted at rest by DrupalConfigStorage::store(), so a
    // direct state read hands back ciphertext. That ciphertext became
    // ai_api_key and the gateway rejected every AI call it authenticated.
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringNotContainsString(
      "state->get('scolta.amazee.credentials')",
      $contents,
      'Reading the credential state directly skips decryption'
    );
    $this->assertStringNotContainsString(
      'use Drupal\Core\State\StateInterface',
      $contents,
      'Nothing in this service reads state now that the store is the only credential path'
    );
  }

  public function testImportsBudgetExceededHandler(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('use Drupal\scolta\AiProvider\Amazee\BudgetExceededHandler', $contents);
  }

  public function testImportsAmazeeBudgetExceededException(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException', $contents);
  }

  public function testConstructorAcceptsTheAmazeeCredentialStore(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertMatchesRegularExpression(
      '/function\s+__construct\s*\([^)]*\?ConfigStorageInterface\s+\$\w+\s*=\s*NULL/s',
      $contents,
      'Constructor must accept the Amazee credential store as an optional parameter'
    );
  }

  public function testConstructorAcceptsBudgetHandlerAsOptional(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertMatchesRegularExpression(
      '/\?BudgetExceededHandler\s+\$\w+\s*=\s*NULL/',
      $contents,
      'BudgetExceededHandler must be an optional constructor parameter'
    );
  }

  public function testBuildConfigChecksAmazeeCreds(): void {
    // The credential array comes from the store rather than from state, so the
    // token is decrypted before it is handed to the shared resolver. It is
    // still the resolver that unpacks litellm_token, which is what keeps this
    // service from deciding the source a second time.
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('$this->amazeeConfigStorage?->load()', $contents);
    $this->assertStringContainsString("'ai_provider'", $contents);
    $this->assertStringContainsString('AmazeeCredentials::fromArray(', $contents);
  }

  public function testGetApiKeySourceDelegatesToTheResolver(): void {
    // It used to return 'amazee' from its own check of the credential store,
    // before it had looked at the environment variable at all — the opposite
    // precedence to the effective-config path (scolta-php#252).
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('return $this->resolveApiKey()->source->value;', $contents);
    $this->assertStringNotContainsString("return 'amazee'", $contents);
  }

  public function testIsAmazeeActiveMethod(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('public function isAmazeeActive()', $contents);
  }

  public function testOverridesBudgetExceptionHook(): void {
    // The budget-conversion logic now lives in a single protected hook that
    // overrides AiServiceAdapter::handlePossibleBudgetException(). The base
    // class owns the try/catch around the AI calls, so the adapter no longer
    // overrides message()/conversation()/messageForOperation() just to wrap them.
    $contents = file_get_contents($this->serviceFile);
    $this->assertMatchesRegularExpression(
      '/protected\s+function\s+handlePossibleBudgetException\s*\(\s*\\\\?RuntimeException/',
      $contents,
      'Adapter must override the protected handlePossibleBudgetException() hook'
    );
  }

  public function testBudgetHookConvertsAndNotifies(): void {
    $contents = file_get_contents($this->serviceFile);
    // The hook detects the Amazee budget signal via the public scolta-php
    // API instead of duplicating the private budget-message constant.
    $this->assertStringContainsString('BudgetAwareProviderDecorator::isBudgetError(', $contents);
    $this->assertStringNotContainsString("'Budget has been exceeded!'", $contents,
      'The budget magic string must not be duplicated — use isBudgetError()');
    $this->assertStringContainsString('new AmazeeBudgetExceededException', $contents);
    $this->assertStringContainsString('budgetHandler?->handle', $contents);
  }

  public function testNoRedundantAiMethodOverrides(): void {
    // The base AiServiceAdapter now owns the budget try/catch, so these
    // overrides must NOT be reintroduced here.
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringNotContainsString('public function message(string $systemPrompt', $contents);
    $this->assertStringNotContainsString('public function conversation(string $systemPrompt', $contents);
    $this->assertStringNotContainsString('public function messageForOperation(string $operation', $contents);
  }

  public function testBuildConfigChecksExplicitKeyBeforeAmazee(): void {
    // Regression: an env/settings key must never be silently rerouted through
    // Amazee. The ordering is no longer expressed here at all — it is the
    // resolver's canonical precedence, with the explicit candidates passed in
    // ahead of the credentials — so what this pins is that buildConfig() asks
    // the resolver rather than ordering the checks itself.
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('$resolved = $this->resolveApiKey();', $contents);
    $this->assertStringContainsString("\$values['ai_api_key'] = \$resolved->key;", $contents);

    preg_match('/public function resolveApiKey\(\): ResolvedApiKey \{(.*?)\n  \}/s', $contents, $match);
    $body = $match[1] ?? '';
    $this->assertNotSame('', $body, 'resolveApiKey() must exist');

    $explicitPos = strpos($body, '$this->explicitKeyCandidates()');
    $amazeePos = strpos($body, 'AmazeeCredentials::fromArray(');
    $this->assertNotFalse($explicitPos, 'resolveApiKey() must pass the explicit candidates');
    $this->assertNotFalse($amazeePos, 'resolveApiKey() must pass the stored credentials');
    $this->assertLessThan(
      $amazeePos,
      $explicitPos,
      'The explicit candidates must be handed to the resolver ahead of the Amazee credentials'
    );
  }

  public function testServicesYamlHasAmazeeServices(): void {
    $yaml = file_get_contents(dirname(__DIR__, 2) . '/scolta.services.yml');
    $this->assertStringContainsString('scolta.amazee_config_storage', $yaml);
    $this->assertStringContainsString('scolta.amazee_budget_handler', $yaml);
    $this->assertStringContainsString('DrupalConfigStorage', $yaml);
    $this->assertStringContainsString('BudgetExceededHandler', $yaml);
  }

  public function testScoltaAiServiceServicesYamlArgumentCount(): void {
    $yaml = file_get_contents(dirname(__DIR__, 2) . '/scolta.services.yml');
    // The service definition should have 5 arguments now.
    $this->assertStringContainsString('@scolta.amazee_budget_handler', $yaml);
    $this->assertStringContainsString('@state', $yaml);
  }

}
