<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests Drush command structural integrity via file inspection.
 *
 * Verifies that each Drush command method exists with correct attributes,
 * command names and aliases match documentation, and constructor parameters
 * align with drush.services.yml arguments.
 */
class ScoltaCommandsValidationTest extends TestCase {

  private string $moduleRoot;
  private string $commandsFile;
  private string $commandsContents;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->commandsFile = $this->moduleRoot . '/src/Commands/ScoltaCommands.php';
    $this->commandsContents = file_get_contents($this->commandsFile);
  }

  // -------------------------------------------------------------------
  // Command methods exist.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('commandMethodProvider')]
  public function testCommandMethodExists(string $methodName): void {
    $this->assertStringContainsString(
      "function {$methodName}(",
      $this->commandsContents,
      "ScoltaCommands must have {$methodName}() method"
    );
  }

  public static function commandMethodProvider(): array {
    return [
      'export' => ['export'],
      'build' => ['build'],
      'rebuildIndex' => ['rebuildIndex'],
      'clearCache' => ['clearCache'],
      'checkSetup' => ['checkSetup'],
      'status' => ['status'],
      'downloadPagefind' => ['downloadPagefind'],
    ];
  }

  // -------------------------------------------------------------------
  // Drush command names match documentation.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('commandNameProvider')]
  public function testCommandNameExists(string $commandName): void {
    $this->assertStringContainsString(
      "name: '{$commandName}'",
      $this->commandsContents,
      "Drush command '{$commandName}' should be defined"
    );
  }

  public static function commandNameProvider(): array {
    return [
      'scolta:export' => ['scolta:export'],
      'scolta:build' => ['scolta:build'],
      'scolta:rebuild-index' => ['scolta:rebuild-index'],
      'scolta:clear-cache' => ['scolta:clear-cache'],
      'scolta:check-setup' => ['scolta:check-setup'],
      'scolta:status' => ['scolta:status'],
      'scolta:download-pagefind' => ['scolta:download-pagefind'],
    ];
  }

  // -------------------------------------------------------------------
  // Drush command aliases.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('commandAliasProvider')]
  public function testCommandAliasExists(string $commandName, string $alias): void {
    $this->assertStringContainsString(
      "aliases: ['{$alias}']",
      $this->commandsContents,
      "Command '{$commandName}' should have alias '{$alias}'"
    );
  }

  public static function commandAliasProvider(): array {
    return [
      'export -> se' => ['scolta:export', 'se'],
      'build -> sb' => ['scolta:build', 'sb'],
      'rebuild-index -> sri' => ['scolta:rebuild-index', 'sri'],
      'clear-cache -> scc' => ['scolta:clear-cache', 'scc'],
      'check-setup -> scs' => ['scolta:check-setup', 'scs'],
      'status -> sst' => ['scolta:status', 'sst'],
      'download-pagefind -> sdp' => ['scolta:download-pagefind', 'sdp'],
    ];
  }

  // -------------------------------------------------------------------
  // Constructor parameters match drush.services.yml argument count.
  // -------------------------------------------------------------------

  public function testConstructorParameterCountMatchesDrushServices(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $args = $drush['services']['scolta.commands']['arguments'] ?? [];

    if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $this->commandsContents, $m)) {
      $params = array_filter(array_map('trim', explode(',', $m[1])));
      $this->assertEquals(
        count($params), count($args),
        'ScoltaCommands constructor param count must match drush.services.yml argument count'
      );
    }
    else {
      $this->fail('ScoltaCommands has no constructor');
    }
  }

  // -------------------------------------------------------------------
  // Constructor accepts expected service types.
  // -------------------------------------------------------------------

  public function testConstructorAcceptsEntityTypeManager(): void {
    $this->assertStringContainsString('EntityTypeManagerInterface $entityTypeManager', $this->commandsContents);
  }

  public function testConstructorAcceptsConfigFactory(): void {
    $this->assertStringContainsString('ConfigFactoryInterface $configFactory', $this->commandsContents);
  }

  public function testConstructorAcceptsHttpClient(): void {
    $this->assertStringContainsString('ClientInterface $httpClient', $this->commandsContents);
  }

  public function testConstructorAcceptsState(): void {
    $this->assertStringContainsString('StateInterface $state', $this->commandsContents);
  }

  public function testConstructorAcceptsCacheBackend(): void {
    $this->assertStringContainsString('CacheBackendInterface $cache', $this->commandsContents);
  }

  public function testConstructorAcceptsScoltaAiService(): void {
    $this->assertStringContainsString('ScoltaAiService $aiService', $this->commandsContents);
  }

  public function testConstructorAcceptsStreamWrapperManager(): void {
    $this->assertStringContainsString('StreamWrapperManagerInterface $streamWrapperManager', $this->commandsContents);
  }

  // -------------------------------------------------------------------
  // Commands extend DrushCommands and call parent::__construct().
  // -------------------------------------------------------------------

  public function testExtendsCorrectBaseClass(): void {
    $this->assertStringContainsString(
      'extends DrushCommands',
      $this->commandsContents,
      'ScoltaCommands must extend DrushCommands'
    );
  }

  public function testCallsParentConstructor(): void {
    $this->assertStringContainsString(
      'parent::__construct()',
      $this->commandsContents,
      'ScoltaCommands must call parent::__construct()'
    );
  }

  // -------------------------------------------------------------------
  // Drush attributes are used (not annotations).
  // -------------------------------------------------------------------

  public function testUsesDrushAttributes(): void {
    $this->assertStringContainsString(
      'use Drush\Attributes as CLI',
      $this->commandsContents,
      'ScoltaCommands should import Drush\Attributes'
    );
  }

  public function testCommandsUseCLICommandAttribute(): void {
    preg_match_all('/#\[CLI\\\\Command\(/', $this->commandsContents, $matches);
    $this->assertGreaterThanOrEqual(7, count($matches[0]),
      'At least 7 commands should use #[CLI\\Command] attribute');
  }

  // -------------------------------------------------------------------
  // drush.services.yml service definition.
  // -------------------------------------------------------------------

  public function testDrushServiceIsTaggedAsCommand(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $tags = $drush['services']['scolta.commands']['tags'] ?? [];

    $hasTag = false;
    foreach ($tags as $tag) {
      if (($tag['name'] ?? '') === 'drush.command') {
        $hasTag = true;
        break;
      }
    }
    $this->assertTrue($hasTag, 'scolta.commands should be tagged with drush.command');
  }

  public function testDrushServiceClassIsCorrect(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $this->assertEquals(
      'Drupal\scolta\Commands\ScoltaCommands',
      $drush['services']['scolta.commands']['class']
    );
  }

  // -------------------------------------------------------------------
  // Export command has expected options.
  // -------------------------------------------------------------------

  public function testExportCommandHasBundleOption(): void {
    $this->assertStringContainsString(
      "'bundle'",
      $this->commandsContents,
      'Export command should have bundle option'
    );
  }

  public function testExportCommandHasOutputDirOption(): void {
    $this->assertStringContainsString(
      "'output-dir'",
      $this->commandsContents,
      'Export command should have output-dir option'
    );
  }

  public function testBuildCommandHasSkipPagefindOption(): void {
    $this->assertStringContainsString(
      "'skip-pagefind'",
      $this->commandsContents,
      'Build command should have skip-pagefind option'
    );
  }

  public function testBuildCommandHasMemoryBudgetOption(): void {
    $this->assertStringContainsString(
      "'memory-budget'",
      $this->commandsContents,
      'Build command should have memory-budget option'
    );
  }

  public function testBuildCommandHasChunkSizeOption(): void {
    $this->assertStringContainsString(
      "'chunk-size'",
      $this->commandsContents,
      'Build command should have chunk-size option'
    );
  }

  public function testBuildCommandUsesFromOptions(): void {
    // Budget resolution is now delegated to MemoryBudgetConfig::fromCliAndConfig().
    $this->assertStringContainsString(
      'MemoryBudgetConfig::fromCliAndConfig(',
      $this->commandsContents,
      'buildWithPhpIndexer() must use MemoryBudgetConfig::fromCliAndConfig() to apply budget and chunk size'
    );
  }

  // -------------------------------------------------------------------
  // Config schema includes chunk_size.
  // -------------------------------------------------------------------

  public function testConfigSchemaHasMemoryBudgetChunkSize(): void {
    $schema = file_get_contents($this->moduleRoot . '/config/schema/scolta.schema.yml');
    $this->assertStringContainsString(
      'chunk_size',
      $schema,
      'Config schema must declare memory_budget.chunk_size'
    );
  }

  public function testConfigInstallHasMemoryBudgetChunkSize(): void {
    $install = file_get_contents($this->moduleRoot . '/config/install/scolta.settings.yml');
    $this->assertStringContainsString(
      'chunk_size',
      $install,
      'Default config must include memory_budget.chunk_size'
    );
  }

  public function testDownloadPagefindCommandHasVersionOption(): void {
    $this->assertStringContainsString(
      "'version'",
      $this->commandsContents,
      'Download command should have version option'
    );
  }

  // -------------------------------------------------------------------
  // Generation counter incremented on build.
  // -------------------------------------------------------------------

  public function testBuildIncrementsGenerationCounter(): void {
    $this->assertStringContainsString(
      'scolta.generation',
      $this->commandsContents,
      'Build should use scolta.generation state for cache invalidation'
    );
  }

}
