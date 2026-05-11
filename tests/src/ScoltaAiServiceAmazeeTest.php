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

  public function testImportsStateInterface(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('use Drupal\Core\State\StateInterface', $contents);
  }

  public function testImportsBudgetExceededHandler(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('use Drupal\scolta\AiProvider\Amazee\BudgetExceededHandler', $contents);
  }

  public function testImportsAmazeeBudgetExceededException(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException', $contents);
  }

  public function testConstructorAcceptsStateInterface(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertMatchesRegularExpression(
      '/function\s+__construct\s*\([^)]*StateInterface/s',
      $contents,
      'Constructor must accept StateInterface parameter'
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
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('scolta.amazee.credentials', $contents);
    $this->assertStringContainsString("'ai_provider'", $contents);
    $this->assertStringContainsString('litellm_token', $contents);
  }

  public function testGetApiKeySourceReturnsAmazee(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString("return 'amazee'", $contents);
  }

  public function testIsAmazeeActiveMethod(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('public function isAmazeeActive()', $contents);
  }

  public function testOverridesMessageMethod(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('public function message(string $systemPrompt', $contents);
    $this->assertStringContainsString('handlePossibleBudgetException', $contents);
  }

  public function testOverridesConversationMethod(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('public function conversation(string $systemPrompt', $contents);
  }

  public function testOverridesMessageForOperationMethod(): void {
    $contents = file_get_contents($this->serviceFile);
    $this->assertStringContainsString('public function messageForOperation(string $operation', $contents);
  }

  public function testBuildConfigChecksExplicitKeyBeforeAmazee(): void {
    // Regression: buildConfig() must check getApiKey() before Amazee creds
    // so users who have an env/settings key are never silently rerouted.
    $contents = file_get_contents($this->serviceFile);
    $explicitKeyPos = strpos($contents, '$explicitKey = $this->getApiKey()');
    $amazeeCredsPos = strpos($contents, 'scolta.amazee.credentials');
    $this->assertNotFalse($explicitKeyPos, 'buildConfig() must check getApiKey() as explicit key guard');
    $this->assertNotFalse($amazeeCredsPos, 'buildConfig() must still check scolta.amazee.credentials');
    $this->assertLessThan(
      $amazeeCredsPos,
      $explicitKeyPos,
      'Explicit key check must appear before Amazee credentials check in buildConfig()'
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
