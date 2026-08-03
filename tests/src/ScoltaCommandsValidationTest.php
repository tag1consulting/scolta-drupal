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

  // -------------------------------------------------------------------
  // Private file path fallback.
  // -------------------------------------------------------------------

  public function testResolveBuildDirMethodExists(): void {
    $this->assertStringContainsString(
      'function resolveBuildDir(',
      $this->commandsContents,
      'ScoltaCommands must have a resolveBuildDir() method for private:// fallback'
    );
  }

  public function testResolveBuildDirFallsBackToPublic(): void {
    preg_match(
      '/function resolveBuildDir\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s',
      $this->commandsContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      "str_starts_with(\$uri, 'private://')",
      $body,
      'resolveBuildDir() must check for private:// scheme'
    );
    $this->assertStringContainsString(
      'scolta-build',
      $body,
      'resolveBuildDir() must fall back to public://scolta-build'
    );
  }

  public function testResolveBuildDirLogsNotice(): void {
    preg_match(
      '/function resolveBuildDir\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s',
      $this->commandsContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      'Private file system not configured',
      $body,
      'resolveBuildDir() must log a notice when falling back from private:// to public://'
    );
  }

  public function testBuildWithPhpIndexerUsesResolveBuildDir(): void {
    preg_match(
      '/function buildWithPhpIndexer\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s',
      $this->commandsContents,
      $match
    );
    $body = $match[1] ?? '';

    $this->assertStringContainsString(
      'resolveBuildDir(',
      $body,
      'buildWithPhpIndexer() must use resolveBuildDir() for the state directory'
    );
  }

  public function testStatusCommandShowsBuildDirectory(): void {
    $this->assertStringContainsString(
      '--- Build Directory ---',
      $this->commandsContents,
      'status command must show a Build Directory section'
    );
  }

  public function testDefaultBuildDirIsPublic(): void {
    $install = file_get_contents($this->moduleRoot . '/config/install/scolta.settings.yml');
    $this->assertStringContainsString(
      "build_dir: 'public://scolta-build'",
      $install,
      'Default install config must use public://scolta-build as build_dir'
    );
  }

  // -------------------------------------------------------------------
  // Auto indexer always uses PHP (no binary check).
  // -------------------------------------------------------------------

  public function testResolveAutoIndexerReturnsPHP(): void {
    // resolveAutoIndexer() must return 'php' without checking binary availability.
    preg_match('/function resolveAutoIndexer\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s', $this->commandsContents, $m);
    $body = $m[1] ?? '';

    $this->assertNotEmpty($body, 'Could not locate resolveAutoIndexer() method body');
    $this->assertStringContainsString(
      "return 'php';",
      $body,
      'resolveAutoIndexer() must return php'
    );
    $this->assertStringNotContainsString(
      'available',
      $body,
      "resolveAutoIndexer() must not check binary availability — auto always uses PHP"
    );
  }

  public function testAutoIndexerStatusDisplaysPhp(): void {
    // The status command must not display 'binary (auto-detected)' — auto now always uses PHP.
    $this->assertStringNotContainsString(
      'binary (auto-detected)',
      $this->commandsContents,
      'Status command must not display "binary (auto-detected)" — auto now always uses PHP'
    );
  }

  // -------------------------------------------------------------------
  // Site name fallback to system.site when scolta site_name is empty.
  // -------------------------------------------------------------------

  public function testExportFallsBackToSystemSiteName(): void {
    $this->assertStringContainsString(
      "system.site",
      $this->commandsContents,
      'export() must fall back to system.site when site_name is empty'
    );
  }

  public function testExportDoesNotHardcodeUnknownSiteName(): void {
    // Both export() and buildWithPhpIndexer() previously defaulted to 'Unknown'
    // instead of reading the real site name from system.site.
    $this->assertStringNotContainsString(
      "'Unknown'",
      $this->commandsContents,
      "Commands must not fall back to hard-coded 'Unknown' — use system.site name instead"
    );
  }

  public function testBuildWithPhpIndexerFallsBackToSystemSiteName(): void {
    // Verify the system.site fallback pattern is used in buildWithPhpIndexer().
    preg_match('/function buildWithPhpIndexer\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s', $this->commandsContents, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate buildWithPhpIndexer() method body');
    $this->assertStringContainsString(
      'system.site',
      $body,
      'buildWithPhpIndexer() must fall back to system.site when site_name is empty'
    );
  }

  // -------------------------------------------------------------------
  // The build command owns its outcome.
  //
  // Structural checks, because this suite runs without a Drupal bootstrap
  // and cannot execute the command. What they pin is the shape of the
  // defect: a build that returned success while a detached process decided
  // what the index would actually contain.
  // -------------------------------------------------------------------

  public function testNoBuildWorkIsDetachedIntoTheBackground(): void {
    // The backgrounding form specifically: a trailing " &" inside the command
    // string, as in exec($cmd . ' >> … 2>&1 &'). Bounded to one line so it
    // cannot run past the call and match an unrelated exec() further down,
    // and matched against code with comments stripped so that a docblock
    // explaining the removal does not read as the thing it describes.
    $this->assertDoesNotMatchRegularExpression(
      '/exec\([^;\n]*&["\']\s*\)/',
      $this->codeWithoutComments(),
      "The build must not background a child with exec('… &'): the parent then exits 0 "
      . 'having indexed nothing it can vouch for, and nothing reads what the child produced'
    );
    $this->assertStringNotContainsString(
      'spawnResumeBackground',
      $this->commandsContents,
      'The detached resume spawner must stay removed'
    );
  }

  /**
   * The command file with comments and docblocks removed.
   */
  private function codeWithoutComments(): string {
    $code = '';
    foreach (token_get_all($this->commandsContents) as $token) {
      if (is_array($token)) {
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
          continue;
        }
        $code .= $token[1];
        continue;
      }
      $code .= $token;
    }

    return $code;
  }

  public function testResumeSegmentsAreWaitedForAndChecked(): void {
    $this->assertStringContainsString(
      'function runResumeChain',
      $this->commandsContents,
      'Resume segments must be driven from the foreground'
    );
    $this->assertStringContainsString(
      'MAX_RESUME_SEGMENTS',
      $this->commandsContents,
      'The resume chain must be bounded rather than spawning until someone notices'
    );
    $this->assertStringContainsString(
      'proc_close',
      $this->commandsContents,
      'A resume segment must be waited on, so its exit status is the parent\'s answer'
    );
  }

  public function testAFailedBuildThrowsSoDrushExitsNonZero(): void {
    preg_match(
      '/function buildWithPhpIndexer\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s',
      $this->commandsContents,
      $m
    );
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate buildWithPhpIndexer() method body');

    $this->assertStringContainsString(
      'throw new \RuntimeException',
      $body,
      'A failed build must throw: a logger()->error() call leaves the exit status at 0, '
      . 'and a zero exit status is the only thing a deploy pipeline reads'
    );
    $this->assertStringNotContainsString(
      "logger()->error('PHP indexer failed",
      $body,
      'The indexer failure path must throw rather than log and return'
    );
  }

  public function testSuccessIsReportedOnlyAfterTheIndexIsVerified(): void {
    $this->assertStringContainsString(
      'verifyIndexComplete',
      $this->commandsContents,
      'The command must verify a usable index exists before announcing one'
    );
    $this->assertStringContainsString(
      'function assertIndexUsable',
      $this->commandsContents,
      'Index verification must have one call site rather than being repeated per path'
    );
  }

  public function testTheResumeCursorIsNotAPageCountUsedAsAnEntityOffset(): void {
    preg_match(
      '/function buildWithPhpIndexer\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s',
      $this->commandsContents,
      $m
    );
    $body = $m[1] ?? '';

    // pages_processed counts pages; the gatherer's cursor walks entities, and
    // one entity yields a page per translation. Passing the first as the
    // second skipped the wrong rows on every translated corpus.
    $this->assertStringNotContainsString(
      '$resumeOffset = $orchestrator->coordinator()->buildState()->getPagesProcessed()',
      $body,
      'A page count must not be handed to the gatherer as an entity offset'
    );
  }

}
