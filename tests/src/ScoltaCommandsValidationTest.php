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
 *
 * The commands ship from two classes since the backend/frontend split — the
 * build pipeline in scolta, the AI commands in scolta_ui — so the assertions
 * about the command surface read both, and the assertions about a particular
 * class's wiring name the class they are about.
 */
class ScoltaCommandsValidationTest extends TestCase {

  private string $moduleRoot;
  private string $commandsFile;
  private string $commandsContents;
  private string $uiCommandsFile;
  private string $uiCommandsContents;
  private string $allCommandsContents;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->commandsFile = $this->moduleRoot . '/src/Commands/ScoltaCommands.php';
    $this->commandsContents = file_get_contents($this->commandsFile);
    $this->uiCommandsFile = $this->moduleRoot . '/modules/scolta_ui/src/Commands/ScoltaUiCommands.php';
    $this->uiCommandsContents = file_get_contents($this->uiCommandsFile);
    $this->allCommandsContents = $this->commandsContents . "\n" . $this->uiCommandsContents;
  }

  // -------------------------------------------------------------------
  // Command methods exist.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('commandMethodProvider')]
  public function testCommandMethodExists(string $methodName): void {
    $this->assertStringContainsString(
      "function {$methodName}(",
      $this->allCommandsContents,
      "The package must have a {$methodName}() Drush command method"
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
      'cachePrompts' => ['cachePrompts'],
      'aiStatus' => ['aiStatus'],
    ];
  }

  // -------------------------------------------------------------------
  // Drush command names match documentation.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('commandNameProvider')]
  public function testCommandNameExists(string $commandName): void {
    $this->assertStringContainsString(
      "name: '{$commandName}'",
      $this->allCommandsContents,
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
      'scolta:cache-prompts' => ['scolta:cache-prompts'],
      'scolta:ai-status' => ['scolta:ai-status'],
    ];
  }

  // -------------------------------------------------------------------
  // Drush command aliases.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('commandAliasProvider')]
  public function testCommandAliasExists(string $commandName, string $alias): void {
    $this->assertStringContainsString(
      "aliases: ['{$alias}']",
      $this->allCommandsContents,
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
      'cache-prompts -> scp' => ['scolta:cache-prompts', 'scp'],
      'ai-status -> sais' => ['scolta:ai-status', 'sais'],
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

  public function testUiConstructorAcceptsScoltaAiService(): void {
    // The AI service moved to the frontend with the commands that use it, and
    // the backend command class must no longer reach for it at all.
    $this->assertStringContainsString('ScoltaAiService $aiService', $this->uiCommandsContents);
    $this->assertStringNotContainsString('ScoltaAiService', $this->commandsContents,
      'The backend Drush commands must not depend on the frontend AI service');
  }

  public function testConstructorAcceptsStreamWrapperManager(): void {
    $this->assertStringContainsString('StreamWrapperManagerInterface $streamWrapperManager', $this->commandsContents);
  }

  // -------------------------------------------------------------------
  // Commands extend DrushCommands and call parent::__construct().
  // -------------------------------------------------------------------

  public function testExtendsCorrectBaseClass(): void {
    foreach ($this->commandClasses() as $class => $contents) {
      $this->assertStringContainsString(
        'extends DrushCommands',
        $contents,
        "{$class} must extend DrushCommands"
      );
    }
  }

  public function testCallsParentConstructor(): void {
    foreach ($this->commandClasses() as $class => $contents) {
      $this->assertStringContainsString(
        'parent::__construct()',
        $contents,
        "{$class} must call parent::__construct()"
      );
    }
  }

  /**
   * The package's Drush command classes, keyed by short name.
   *
   * @return array<string, string>
   *   File contents keyed by class name.
   */
  private function commandClasses(): array {
    return [
      'ScoltaCommands' => $this->commandsContents,
      'ScoltaUiCommands' => $this->uiCommandsContents,
    ];
  }

  // -------------------------------------------------------------------
  // Drush attributes are used (not annotations).
  // -------------------------------------------------------------------

  public function testUsesDrushAttributes(): void {
    foreach ($this->commandClasses() as $class => $contents) {
      $this->assertStringContainsString(
        'use Drush\Attributes as CLI',
        $contents,
        "{$class} should import Drush\Attributes"
      );
    }
  }

  public function testCommandsUseCLICommandAttribute(): void {
    preg_match_all('/#\[CLI\\\\Command\(/', $this->allCommandsContents, $matches);
    $this->assertGreaterThanOrEqual(9, count($matches[0]),
      'At least 9 commands should use #[CLI\\Command] attribute');
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

  public function testUiModuleRegistersItsOwnCommandService(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/modules/scolta_ui/drush.services.yml');
    $definition = $drush['services']['scolta_ui.commands'] ?? NULL;

    $this->assertNotNull($definition,
      'scolta_ui must register its own Drush command service, so its commands exist on a frontend-only install');
    $this->assertEquals('Drupal\scolta_ui\Commands\ScoltaUiCommands', $definition['class']);
    $this->assertContains('drush.command', array_column($definition['tags'] ?? [], 'name'));
  }

  public function testUiConstructorParameterCountMatchesDrushServices(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/modules/scolta_ui/drush.services.yml');
    $args = $drush['services']['scolta_ui.commands']['arguments'] ?? [];

    $this->assertTrue(
      (bool) preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $this->uiCommandsContents, $m),
      'ScoltaUiCommands has no constructor'
    );
    $params = array_filter(array_map('trim', explode(',', $m[1])));
    $this->assertCount(
      count($args), $params,
      'ScoltaUiCommands constructor param count must match its drush.services.yml argument count'
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

  public function testBuildCommandHasEntityIdsOption(): void {
    $this->assertStringContainsString(
      "'entity-ids'",
      $this->commandsContents,
      'Build command should have entity-ids option'
    );
  }

  public function testEntityIdsAreFilteredToPublishedAndSkipsAreLogged(): void {
    preg_match(
      '/function resolveEntityIds\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s',
      $this->commandsContents,
      $m
    );
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate resolveEntityIds() method body');

    $this->assertStringContainsString(
      'publishedIds(',
      $body,
      'resolveEntityIds() must apply the same publishability rule as gather()'
    );
    $this->assertStringContainsString(
      'could not be loaded',
      $body,
      'resolveEntityIds() must log a notice naming the IDs it skipped'
    );
  }

  public function testEntityIdsBuildUsesTheSharedGatherByIdsPipeline(): void {
    preg_match(
      '/function buildWithPhpIndexer\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s',
      $this->commandsContents,
      $m
    );
    $body = $m[1] ?? '';

    $this->assertStringContainsString(
      'gatherByIds(',
      $body,
      'An --entity-ids build must stream through gatherByIds() so translations, field mappings, and the alter hook apply'
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
    $schema = PackageManifest::rawSettingsSchema();
    $this->assertStringContainsString(
      'chunk_size',
      $schema,
      'Config schema must declare memory_budget.chunk_size'
    );
  }

  public function testConfigInstallHasMemoryBudgetChunkSize(): void {
    $install = PackageManifest::rawSettings();
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
    $install = PackageManifest::rawSettings();
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

  public function testResumeSegmentsForwardTheOptionsThatChangeWhatABuildReads(): void {
    // Matched against code with comments stripped, so a comment explaining an
    // option does not read as the code that forwards it.
    preg_match(
      '/function runResumeChain\b[^{]*\{(.*?)(?=\n  (public|private|protected) function|\n})/s',
      $this->codeWithoutComments(),
      $m
    );
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate runResumeChain() method body');

    // Every option that changes what a segment builds or reads must be
    // forwarded, or a segmented build silently behaves differently from an
    // unsegmented one. --force is the one that was missing: unforced segments
    // served the tail of the corpus from cached references against a manifest
    // the aborted parent never pruned, so a forced build big enough to
    // segment degraded to incremental and reported success.
    foreach (['--entity-type=', '--bundle=', '--entity-ids=', '--chunk-size=', '--force', '--memory-budget='] as $option) {
      $this->assertStringContainsString(
        $option,
        $body,
        "runResumeChain() must forward {$option} to the segments it spawns"
      );
    }
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
