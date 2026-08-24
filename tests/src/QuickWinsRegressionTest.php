<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression tests for the quality quick-wins audit fixes.
 *
 * Source/YAML-inspection tests in the established no-bootstrap style. Each
 * section pins one audit finding so the defect cannot silently return.
 */
class QuickWinsRegressionTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  private function src(string $relative): string {
    return file_get_contents($this->moduleRoot . '/' . $relative);
  }

  // -------------------------------------------------------------------
  // hook_requirements() must read the real config key (pagefind.binary).
  // -------------------------------------------------------------------

  public function testRequirementsReadsExistingConfigKey(): void {
    $install = $this->src('scolta.install');
    $this->assertStringContainsString("get('pagefind.binary')", $install,
      'hook_requirements() must read pagefind.binary');
    $this->assertStringNotContainsString("get('pagefind_binary')", $install,
      "The flat 'pagefind_binary' key does not exist in scolta.settings");

    // The key it reads must actually ship in install config.
    $installConfig = PackageManifest::settings();
    $this->assertArrayHasKey('binary', $installConfig['pagefind']);
  }

  // -------------------------------------------------------------------
  // Provider reporting keys off the CONFIGURED provider, not module
  // presence (same class as #125).
  // -------------------------------------------------------------------

  public function testHealthControllerConditionsProviderOverrideOnConfig(): void {
    $contents = $this->src('modules/scolta_ui/src/Controller/HealthController.php');
    $this->assertMatchesRegularExpression(
      "/aiProvider === 'drupal_ai'\s*&&\s*\\\$this->aiService->hasDrupalAiModule\(\)/",
      $contents,
      'Health must only report drupal-ai when the configured provider is drupal_ai AND the module exists'
    );
  }

  public function testDrushStatusConditionsProviderReportOnConfig(): void {
    $contents = $this->src('modules/scolta_ui/src/Commands/ScoltaUiCommands.php');
    $this->assertMatchesRegularExpression(
      "/\\\$provider === 'drupal_ai'\s*&&\s*\\\$this->aiService->hasDrupalAiModule\(\)/",
      $contents,
      'drush scolta:ai-status must only report the Drupal AI module when drupal_ai is the configured provider'
    );
  }

  // -------------------------------------------------------------------
  // scolta:clear-cache must be targeted — never wipe the shared bin.
  // -------------------------------------------------------------------

  public function testClearCacheDoesNotWipeSharedBin(): void {
    $contents = $this->src('modules/scolta_ui/src/Commands/ScoltaUiCommands.php');
    $this->assertStringNotContainsString('$this->cache->deleteAll()', $contents,
      'clearCache() must not wipe the shared cache.default bin');
  }

  public function testClearCacheBumpsGenerationAndDeletesPromptKeys(): void {
    $contents = $this->src('modules/scolta_ui/src/Commands/ScoltaUiCommands.php');
    $body = $this->extractMethod($contents, 'clearCache');
    $this->assertStringContainsString("'scolta.generation'", $body,
      'clearCache() must bump the generation counter — the live AI-cache invalidation mechanism');
    $this->assertStringContainsString('deleteMultiple', $body,
      'clearCache() must delete the fixed resolved-prompt keys, not everything');
    $this->assertStringContainsString("'scolta.prompt.expand_query'", $body);
    $this->assertStringContainsString("'scolta.prompt.summarize'", $body);
    $this->assertStringContainsString("'scolta.prompt.follow_up'", $body);
  }

  // -------------------------------------------------------------------
  // triggerRebuild(): binary success path bumps the generation; the dead
  // 'scolta:expand' tag (entries are set with no tags) is gone.
  // -------------------------------------------------------------------

  public function testTriggerRebuildBinarySuccessBumpsGeneration(): void {
    $contents = $this->src('src/Plugin/search_api/backend/ScoltaBackend.php');
    $body = $this->extractMethod($contents, 'triggerRebuild');
    $this->assertStringContainsString("'scolta.generation'", $body,
      'A successful binary rebuild must bump scolta.generation or AI caches stay stale for up to 30 days');
  }

  public function testDeadExpandTagInvalidationIsGone(): void {
    $contents = $this->src('src/Plugin/search_api/backend/ScoltaBackend.php');
    $this->assertStringNotContainsString("'scolta:expand'", $contents,
      "DrupalCacheDriver sets entries with NO tags — invalidating tag 'scolta:expand' is a no-op");
  }

  public function testBackendUsesInjectedQueueAndState(): void {
    $contents = $this->src('src/Plugin/search_api/backend/ScoltaBackend.php');
    $this->assertStringNotContainsString('\Drupal::queue(', $contents,
      'ScoltaBackend must use the injected queue factory');
    $this->assertStringContainsString("\$container->get('queue')", $contents);
    $this->assertStringContainsString("\$container->get('state')", $contents);
  }

  // -------------------------------------------------------------------
  // Dismiss route: CSRF protection + open-redirect hardening.
  // -------------------------------------------------------------------

  public function testDismissRouteRequiresCsrfToken(): void {
    $routing = PackageManifest::routes();
    $this->assertSame(
      'TRUE',
      $routing['scolta.dismiss_rebuild_notice']['requirements']['_csrf_token'] ?? NULL,
      'The state-changing dismiss GET route must require a CSRF token'
    );
  }

  public function testDismissControllerRejectsProtocolRelativeDestination(): void {
    $contents = $this->src('src/Controller/DismissRebuildNoticeController.php');
    $this->assertStringContainsString("!str_starts_with(\$destination, '//')", $contents,
      "str_starts_with(\$destination, '/') alone passes protocol-relative '//evil.com'");
    $this->assertStringContainsString('LocalRedirectResponse', $contents,
      'Redirects must be constrained to local URLs');
    $this->assertStringContainsString('Url::fromUserInput(', $contents,
      'The destination must be validated as user-input path, not echoed raw');
  }

  public function testDismissControllerInjectsUserData(): void {
    $contents = $this->src('src/Controller/DismissRebuildNoticeController.php');
    $this->assertStringContainsString("\$container->get('user.data')", $contents);
    $this->assertStringNotContainsString("\\Drupal::service('user.data')", $contents);
  }

  // -------------------------------------------------------------------
  // Config schema: wildcard '*' mapping keys are not a supported schema
  // construct — arbitrary-key maps must be sequences.
  // -------------------------------------------------------------------

  public function testSchemaHasNoWildcardMappingKeys(): void {
    $schema = PackageManifest::settingsSchema();
    $wildcards = [];
    $walk = function (array $node, string $path) use (&$walk, &$wildcards): void {
      foreach ($node as $key => $value) {
        if ($key === 'mapping' && is_array($value) && array_key_exists('*', $value)) {
          $wildcards[] = $path;
        }
        if (is_array($value)) {
          $walk($value, $path . '.' . $key);
        }
      }
    };
    $walk($schema, 'schema');
    $this->assertSame([], $wildcards,
      "mapping: {'*': ...} is unsupported in config schema; use type: sequence");
  }

  public function testArbitraryKeyMapsAreSequences(): void {
    $schema = PackageManifest::settingsSchema();
    $settings = $schema['scolta.settings']['mapping'];
    $this->assertSame('sequence', $settings['sortable_field_descriptions']['type']);
    $this->assertSame('sequence', $settings['filter_field_descriptions']['type']);
    $this->assertSame('sequence', $settings['field_mappings']['mapping']['sortable']['type']);
    $this->assertSame('sequence', $settings['field_mappings']['mapping']['filters']['type']);
  }

  public function testBackendSchemaCoversAutoRebuildDelay(): void {
    $schema = PackageManifest::settingsSchema();
    $backend = $schema['search_api.backend.plugin.scolta_pagefind']['mapping'];
    $this->assertSame('integer', $backend['auto_rebuild_delay']['type'] ?? NULL,
      'auto_rebuild_delay is saved by ScoltaBackend::submitConfigurationForm() and must have schema');
  }

  // -------------------------------------------------------------------
  // Settings form: no hand-rolled exec() — the injected PagefindBuilder
  // (binary allowlist, Symfony Process, timeout) does the build.
  // -------------------------------------------------------------------

  public function testSettingsFormDoesNotShellOut(): void {
    $contents = $this->src('src/Form/ScoltaIndexSettingsForm.php');
    $this->assertDoesNotMatchRegularExpression('/\bexec\s*\(/', $contents,
      'rebuildWithBinary() must not build shell commands');
    $this->assertStringContainsString('$this->pagefindBuilder->build(', $contents,
      'rebuildWithBinary() must run the binary through the injected PagefindBuilder');
  }

  public function testSettingsFormValidatesRecencyCurveJson(): void {
    $contents = $this->src('modules/scolta_ui/src/Form/ScoltaSettingsForm.php');
    $body = $this->extractMethod($contents, 'validateForm');
    $this->assertStringContainsString("'recency_curve'", $body,
      'Malformed recency-curve JSON must produce a form error, not be silently discarded');
  }

  public function testSettingsFormValidatesPipeLines(): void {
    // Each form guards the pipe fields it renders. The two description fields
    // are query-time and stayed with the frontend; the two field mappings are
    // build-time and went to the index form, and the guard went with them —
    // validating a field a form does not render guards nothing.
    $guards = [
      'modules/scolta_ui/src/Form/ScoltaSettingsForm.php' => [
        'sortable_field_descriptions',
        'filter_field_descriptions',
      ],
      'src/Form/ScoltaIndexSettingsForm.php' => [
        'field_mapping_sortable',
        'field_mapping_filters',
      ],
    ];

    foreach ($guards as $file => $fields) {
      $body = $this->extractMethod($this->src($file), 'validateForm');
      foreach ($fields as $field) {
        $this->assertStringContainsString("'{$field}'", $body,
          "Pipe-less lines in {$field} must produce a form error in {$file}, not be silently dropped");
      }
    }
  }

  public function testSettingsFormInjectsCacheTagsInvalidator(): void {
    $contents = $this->src('src/Form/ScoltaIndexSettingsForm.php');
    $this->assertStringContainsString("\$container->get('cache_tags.invalidator')", $contents);
    $this->assertStringNotContainsString("\\Drupal::service('cache_tags.invalidator')", $contents);
  }

  // -------------------------------------------------------------------
  // Hardcoded admin paths → routes / validated user input.
  // -------------------------------------------------------------------

  public function testBudgetHandlerUsesRouteForAmazeeSettings(): void {
    $contents = $this->src('modules/scolta_ui/src/AiProvider/Amazee/BudgetExceededHandler.php');
    $this->assertStringContainsString("Url::fromRoute('scolta.settings.amazee')", $contents);
    $this->assertStringNotContainsString("'/admin/config/search/scolta/amazee'", $contents);
  }

  public function testSettingsFormResolvesAiProvidersPath(): void {
    $contents = $this->src('modules/scolta_ui/src/Form/ScoltaSettingsForm.php');
    $this->assertStringNotContainsString('href="/admin/config/ai/providers"', $contents,
      'Hardcoded hrefs break subdirectory installs — resolve via Url::fromUserInput()');
    $this->assertStringContainsString("Url::fromUserInput('/admin/config/ai/providers')", $contents);
  }

  public function testSearchBlockUsesSettingsRoute(): void {
    $contents = $this->src('modules/scolta_ui/src/Plugin/Block/ScoltaSearchBlock.php');
    $this->assertStringNotContainsString('"/admin/config/search/scolta"', $contents);
    $this->assertStringContainsString("Url::fromRoute('scolta.settings')", $contents);
  }

  // -------------------------------------------------------------------
  // Search block cache metadata + i18n.
  // -------------------------------------------------------------------

  public function testSearchBlockVariesOnPermissions(): void {
    $contents = $this->src('modules/scolta_ui/src/Plugin/Block/ScoltaSearchBlock.php');
    $this->assertStringContainsString("'user.permissions'", $contents,
      'Output differs for administer-scolta users; the render cache must vary on permissions');
  }

  public function testSearchBlockVariesOnContentLanguage(): void {
    $contents = $this->src('modules/scolta_ui/src/Plugin/Block/ScoltaSearchBlock.php');
    $this->assertStringContainsString("'languages:language_content'", $contents,
      'drupalSettings carries currentLanguage; the render cache must vary on content language');
  }

  public function testSearchBlockDependsOnSiteConfig(): void {
    $contents = $this->src('modules/scolta_ui/src/Plugin/Block/ScoltaSearchBlock.php');
    $this->assertStringContainsString("'config:system.site'", $contents,
      'The site-name fallback requires the system.site config cache tag');
  }

  public function testSearchBlockInjectsFormerStatics(): void {
    $contents = $this->src('modules/scolta_ui/src/Plugin/Block/ScoltaSearchBlock.php');
    $this->assertStringNotContainsString('\Drupal::', $contents,
      'ScoltaSearchBlock must use injected services');
    $this->assertStringContainsString("\$container->get('current_user')", $contents);
    $this->assertStringContainsString("\$container->get('stream_wrapper_manager')", $contents);
  }

  public function testSearchBlockTranslatesUserFacingStrings(): void {
    $contents = $this->src('modules/scolta_ui/src/Plugin/Block/ScoltaSearchBlock.php');
    $this->assertStringContainsString("\$this->t('Powered by Scolta')", $contents);
    $this->assertMatchesRegularExpression('/\$this->t\(\s*\n?\s*\'<p><strong>Scolta:<\/strong> No search index found/', $contents);
  }

  // -------------------------------------------------------------------
  // ScoltaAiService: config overrides + DI.
  // -------------------------------------------------------------------

  public function testBuildConfigHonorsConfigOverrides(): void {
    $contents = $this->src('modules/scolta_ui/src/Service/ScoltaAiService.php');
    $this->assertStringNotContainsString('getRawData()', $contents,
      'getRawData() bypasses settings.php $config overrides — AI traffic must see them');
    $this->assertStringContainsString('$drupalConfig->get()', $contents);
  }

  public function testAiServiceHasNoStaticServiceCalls(): void {
    $contents = $this->src('modules/scolta_ui/src/Service/ScoltaAiService.php');
    $this->assertStringNotContainsString('\Drupal::', $contents,
      'ScoltaAiService must use injected services (@?ai.provider for the optional plugin manager)');
  }

  public function testAiServiceDefinitionInjectsOptionalAiProvider(): void {
    $services = ['services' => PackageManifest::services()];
    $args = $services['services']['scolta.ai_service']['arguments'];
    $this->assertContains('@?ai.provider', $args,
      'The ai.provider plugin manager must be optionally injected');
    $this->assertContains('@scolta.amazee_config_storage', $args);
  }

  // -------------------------------------------------------------------
  // Default-model literal lives in exactly one place.
  // -------------------------------------------------------------------

  public function testDefaultModelConstantMatchesInstallConfig(): void {
    $installConfig = PackageManifest::settings();
    $contents = $this->src('modules/scolta_ui/src/Form/ScoltaSettingsForm.php');
    $this->assertMatchesRegularExpression(
      "/public const DEFAULT_AI_MODEL = '" . preg_quote($installConfig['ai_model'], '/') . "';/",
      $contents,
      'ScoltaSettingsForm::DEFAULT_AI_MODEL must match the shipped install default'
    );
  }

  /**
   * The default-model literal is never duplicated at an Amazee binding site.
   *
   * The trial form used to compare ai_model against the constant before
   * overwriting it. Under scolta-drupal#187 that comparison is gone with the
   * write itself — gateway aliases have their own key, so there is no
   * operator-chosen value there to protect — and the only remaining consumer of
   * the constant outside the settings form is the migration hook, which resets
   * ai_model to it. Both files must still reach for the constant rather than
   * repeating the literal.
   */
  public function testAmazeeBindingSitesNeverDuplicateTheDefaultModelLiteral(): void {
    $this->assertStringNotContainsString("'claude-sonnet-", $this->src('modules/scolta_ui/src/Form/AmazeeSettingsForm.php'),
      'No duplicated model literal in AmazeeSettingsForm');

    // scolta.install spells the literal exactly once, as the value of the
    // constant its update hook resets ai_model to. It cannot borrow the form's
    // DEFAULT_AI_MODEL for two reasons that point the same way: an update hook
    // is a historical record and must not follow a moving default, and since
    // the backend/frontend split that form is in the other module, which this
    // one must not reach into.
    $install = $this->src('scolta.install');
    $this->assertSame(
      1,
      substr_count($install, "'claude-sonnet-"),
      'scolta.install must spell the model literal once, in the pinned constant'
    );
    $this->assertStringContainsString(
      "const _SCOLTA_UPDATE_10003_DEFAULT_AI_MODEL = 'claude-sonnet-",
      $install,
      'The one occurrence must be the pinned update constant'
    );
    $this->assertStringContainsString('_SCOLTA_UPDATE_10003_DEFAULT_AI_MODEL', $install,
      'The migration hook must reset ai_model to the constant pinned beside it');
  }

  // -------------------------------------------------------------------
  // Lint scope: .module/.install are linted, warnings are not suppressed.
  // -------------------------------------------------------------------

  public function testPhpcsCoversModuleAndInstallFiles(): void {
    $ruleset = $this->src('phpcs.xml.dist');
    $this->assertStringContainsString('<file>scolta.module</file>', $ruleset);
    $this->assertStringContainsString('<file>scolta.install</file>', $ruleset);
  }

  public function testCiLintDoesNotSuppressWarnings(): void {
    $ci = $this->src('.github/workflows/ci.yml');
    $this->assertStringNotContainsString('--warning-severity=0', $ci,
      'CI must not silence phpcs warnings');
  }

  // -------------------------------------------------------------------
  // Dead code stays dead.
  // -------------------------------------------------------------------

  public function testDeadBatchProcessChunkIsRemoved(): void {
    $contents = $this->src('src/Batch/ScoltaBatchOperations.php');
    $this->assertStringNotContainsString('public static function processChunk(', $contents,
      'processChunk() had no callers (loadAndProcessChunk replaced it)');
  }

  public function testDeadMemoryBudgetHelpersAreRemoved(): void {
    $contents = $this->src('src/Form/MemoryBudgetSettingsFieldSet.php');
    $this->assertStringNotContainsString('function extract(', $contents);
    $this->assertStringNotContainsString('function formatBytes(', $contents);
  }

  public function testAmazeeFormDropsUnusedClientProperty(): void {
    $contents = $this->src('modules/scolta_ui/src/Form/AmazeeSettingsForm.php');
    $this->assertStringNotContainsString('private readonly AmazeeClient $amazeeClient', $contents,
      'The constructor-injected AmazeeClient was never read');
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  /**
   * Extract a method body by name via brace counting.
   */
  private function extractMethod(string $contents, string $method): string {
    $pos = strpos($contents, "function {$method}(");
    $this->assertNotFalse($pos, "Method {$method}() not found");
    $start = strpos($contents, '{', $pos);
    $depth = 0;
    for ($i = $start, $len = strlen($contents); $i < $len; $i++) {
      if ($contents[$i] === '{') {
        $depth++;
      }
      elseif ($contents[$i] === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($contents, $start, $i - $start + 1);
        }
      }
    }
    $this->fail("Unbalanced braces extracting {$method}()");
  }

}
