<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests the ScoltaSearchBlock plugin via file inspection.
 *
 * Verifies the @Block annotation, build() render array structure,
 * create() factory method, and the container div ID. These tests
 * do not require a Drupal bootstrap.
 */
class ScoltaSearchBlockTest extends TestCase {

  private string $moduleRoot;
  private string $blockFile;
  private string $blockContents;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->blockFile = $this->moduleRoot . '/modules/scolta_ui/src/Plugin/Block/ScoltaSearchBlock.php';
    $this->blockContents = file_get_contents($this->blockFile);
  }

  // -------------------------------------------------------------------
  // Block annotation / plugin ID.
  // -------------------------------------------------------------------

  public function testBlockAnnotationExists(): void {
    $this->assertStringContainsString(
      '@Block(',
      $this->blockContents,
      'ScoltaSearchBlock must have @Block annotation'
    );
  }

  public function testBlockIdIsScoltaSearch(): void {
    $this->assertStringContainsString(
      'id = "scolta_search"',
      $this->blockContents,
      'Block plugin ID must be "scolta_search"'
    );
  }

  public function testBlockAdminLabel(): void {
    $this->assertStringContainsString(
      'admin_label = @Translation("Scolta Search")',
      $this->blockContents,
      'Block admin label should be "Scolta Search"'
    );
  }

  public function testBlockCategory(): void {
    $this->assertStringContainsString(
      'category = @Translation("Search")',
      $this->blockContents,
      'Block category should be "Search"'
    );
  }

  // -------------------------------------------------------------------
  // Class structure.
  // -------------------------------------------------------------------

  public function testExtendsBlockBase(): void {
    $this->assertStringContainsString(
      'extends BlockBase',
      $this->blockContents,
      'ScoltaSearchBlock must extend BlockBase'
    );
  }

  public function testImplementsContainerFactoryPluginInterface(): void {
    $this->assertStringContainsString(
      'implements ContainerFactoryPluginInterface',
      $this->blockContents,
      'ScoltaSearchBlock must implement ContainerFactoryPluginInterface'
    );
  }

  // -------------------------------------------------------------------
  // create() factory method.
  // -------------------------------------------------------------------

  public function testHasCreateMethod(): void {
    $this->assertStringContainsString(
      'public static function create(ContainerInterface $container',
      $this->blockContents,
      'ScoltaSearchBlock must have a create() factory method'
    );
  }

  public function testCreateMethodAcceptsBlockPluginParams(): void {
    // Block create() has a different signature from controllers: it gets
    // $configuration, $plugin_id, $plugin_definition in addition to $container.
    $this->assertStringContainsString(
      'array $configuration, $plugin_id, $plugin_definition',
      $this->blockContents,
      'create() must accept block plugin parameters'
    );
  }

  public function testCreateInjectsScoltaAiService(): void {
    $this->assertStringContainsString(
      "'scolta.ai_service'",
      $this->blockContents,
      'create() must inject scolta.ai_service'
    );
  }

  public function testCreateInjectsFileUrlGenerator(): void {
    $this->assertStringContainsString(
      "'file_url_generator'",
      $this->blockContents,
      'create() must inject file_url_generator'
    );
  }

  public function testCreateInjectsConfigFactory(): void {
    $this->assertStringContainsString(
      "'config.factory'",
      $this->blockContents,
      'create() must inject config.factory'
    );
  }

  public function testCreateInjectsLanguageManager(): void {
    $this->assertStringContainsString(
      "'language_manager'",
      $this->blockContents,
      'create() must inject language_manager for current content language detection'
    );
  }

  // -------------------------------------------------------------------
  // build() method and render array.
  // -------------------------------------------------------------------

  public function testHasBuildMethod(): void {
    $this->assertStringContainsString(
      'function build(): array',
      $this->blockContents,
      'ScoltaSearchBlock must have build() returning array'
    );
  }

  public function testBuildReturnsMarkupWithScoltaSearchDiv(): void {
    $this->assertStringContainsString(
      '<div id="scolta-search"></div>',
      $this->blockContents,
      'build() must include a <div id="scolta-search"></div>'
    );
  }

  public function testContainerDivIdIsScoltaSearch(): void {
    $this->assertStringContainsString(
      "'#scolta-search'",
      $this->blockContents,
      'Container selector must be #scolta-search'
    );
  }

  public function testBuildAttachesSearchLibrary(): void {
    $this->assertStringContainsString(
      "'scolta_ui/search'",
      $this->blockContents,
      'build() must attach scolta_ui/search library'
    );
  }

  public function testBuildAttachesDrupalBridgeLibrary(): void {
    $this->assertStringContainsString(
      "'scolta_ui/drupal_bridge'",
      $this->blockContents,
      'build() must attach scolta_ui/drupal_bridge library'
    );
  }

  public function testBuildInjectsDrupalSettings(): void {
    $this->assertStringContainsString(
      "'drupalSettings'",
      $this->blockContents,
      'build() must inject drupalSettings'
    );
  }

  // -------------------------------------------------------------------
  // drupalSettings includes expected configuration keys.
  // -------------------------------------------------------------------

  public function testSettingsIncludesScoringConfig(): void {
    $this->assertStringContainsString(
      "'scoring'",
      $this->blockContents,
      'drupalSettings should include scoring configuration'
    );
  }

  public function testSettingsIncludesEndpoints(): void {
    $this->assertStringContainsString(
      "'endpoints'",
      $this->blockContents,
      'drupalSettings should include API endpoints'
    );
  }

  public function testSettingsIncludesAllEndpoints(): void {
    // The three URLs are AiOrigin's to produce — they depend on whether the
    // AI origin is this site or another one — so the block delegates and the
    // keys are asserted where they are now written.
    $this->assertStringContainsString(
      '$this->aiOrigin->endpoints()',
      $this->blockContents,
      'build() must take the AI endpoints from the AI origin service, so a remote origin reaches the browser'
    );

    $origin = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Service/AiOrigin.php');
    foreach (['expand', 'summarize', 'followup'] as $endpoint) {
      $this->assertStringContainsString(
        "'{$endpoint}' =>",
        $origin,
        "AiOrigin::endpoints() should include '{$endpoint}'"
      );
    }
  }

  public function testSettingsIncludesPagefindPath(): void {
    $this->assertStringContainsString(
      "'pagefindPath'",
      $this->blockContents,
      'drupalSettings should include pagefindPath'
    );
  }

  public function testSettingsIncludesSiteName(): void {
    $this->assertStringContainsString(
      "'siteName'",
      $this->blockContents,
      'drupalSettings should include siteName'
    );
  }

  public function testSettingsIncludesWasmPath(): void {
    $this->assertStringContainsString(
      "'wasmPath'",
      $this->blockContents,
      'drupalSettings should include wasmPath for client-side WASM scoring'
    );
  }

  /**
   * The facet-loading mode must reach window.scolta.
   *
   * Without this key the bundle falls back to 'eager', which is the correct
   * default but silently ignores an administrator who chose otherwise — the
   * setting would appear to save and do nothing.
   */
  public function testSettingsIncludesFacetMode(): void {
    $this->assertStringContainsString(
      "'facetMode'",
      $this->blockContents,
      'drupalSettings should include facetMode so the setting reaches the bundle'
    );
  }

  /**
   * facetMode must be read from Drupal config, not from ScoltaConfig.
   *
   * The behavior lives entirely in the vendored js/scolta.js, so the setting has
   * to work against any scolta-php in the supported range — including one
   * predating the ScoltaConfig property. This is the same reasoning the SAYT
   * keys are passed under.
   */
  public function testFacetModeIsReadFromDrupalConfig(): void {
    $this->assertMatchesRegularExpression(
      "/'facetMode'\s*=>.*\\\$drupalConfig->get\('facet_mode'\)/s",
      $this->blockContents,
      'facetMode must come from $drupalConfig->get(\'facet_mode\')'
    );
  }

  /**
   * An unrecognized stored mode must clamp to 'eager', never pass through.
   *
   * A typo reaching the bundle would clamp there anyway, but a value the block
   * refuses to vouch for should not be put in the page payload at all.
   */
  public function testFacetModeClampsUnknownValuesToEager(): void {
    $this->assertMatchesRegularExpression(
      "/in_array\(\\\$drupalConfig->get\('facet_mode'\), \['eager', 'deferred', 'disabled'\], TRUE\)/",
      $this->blockContents,
      'facetMode must be validated against the three supported modes'
    );
  }

  public function testSettingsIncludesCurrentLanguage(): void {
    $this->assertStringContainsString(
      "'currentLanguage'",
      $this->blockContents,
      'drupalSettings should include currentLanguage for JS auto-language filtering'
    );
  }

  public function testCurrentLanguageUsesContentLanguageType(): void {
    $this->assertStringContainsString(
      'LanguageInterface::TYPE_CONTENT',
      $this->blockContents,
      'getCurrentLanguage() must use TYPE_CONTENT so URL-prefix negotiation applies'
    );
  }

  // -------------------------------------------------------------------
  // Constructor accepts expected types.
  // -------------------------------------------------------------------

  public function testConstructorAcceptsExpectedTypes(): void {
    $expectedTypes = [
      'ScoltaAiService',
      'FileUrlGeneratorInterface',
      'ConfigFactoryInterface',
      'LanguageManagerInterface',
    ];

    foreach ($expectedTypes as $type) {
      $this->assertStringContainsString($type, $this->blockContents,
        "Constructor should accept {$type}");
    }
  }

  public function testConstructorCallsParentConstructor(): void {
    $this->assertStringContainsString(
      'parent::__construct($configuration, $plugin_id, $plugin_definition)',
      $this->blockContents,
      'Constructor must call parent::__construct with block params'
    );
  }

  // -------------------------------------------------------------------
  // resolvePagefindUrl helper.
  // -------------------------------------------------------------------

  public function testHasResolvePagefindUrlMethod(): void {
    $this->assertStringContainsString(
      'function resolvePagefindUrl(',
      $this->blockContents,
      'ScoltaSearchBlock should have resolvePagefindUrl helper'
    );
  }

  public function testResolvePagefindUrlHandlesStreamWrappers(): void {
    $this->assertStringContainsString(
      '://',
      $this->blockContents,
      'resolvePagefindUrl should handle stream wrapper URIs'
    );
  }

  // -------------------------------------------------------------------
  // WASM path — resolved from the deployed public files copy.
  // -------------------------------------------------------------------

  public function testWasmPathResolvesDeployedAsset(): void {
    // The WASM glue is deployed by AssetDeployer, so the block must resolve
    // its URL from AssetDeployer::DIRECTORY through the file URL generator —
    // which handles subdirectory installs and non-default public file paths,
    // where a hand-built module-relative path would not.
    $this->assertStringContainsString(
      'generateString(AssetDeployer::DIRECTORY',
      $this->blockContents,
      'wasmPath must be generated from the deployed AssetDeployer::DIRECTORY copy via the file URL generator'
    );
  }

  public function testWasmPathDoesNotHardcodeModulePath(): void {
    $this->assertStringNotContainsString(
      'js/wasm/scolta_core.js',
      $this->blockContents,
      'wasmPath must not point into the module directory: the bundle is not shipped there'
    );
  }

  // -------------------------------------------------------------------
  // Cache tags — scoring config changes must invalidate cached pages.
  // -------------------------------------------------------------------

  public function testBuildDeclaresScoringConfigCacheTag(): void {
    $this->assertStringContainsString(
      "'config:scolta_ui.settings'",
      $this->blockContents,
      "build() must include 'config:scolta_ui.settings' cache tag so Drupal invalidates cached pages when scoring config is saved"
    );
  }

  public function testBuildDeclaresSearchIndexCacheTag(): void {
    $this->assertStringContainsString(
      "'scolta_search_index'",
      $this->blockContents,
      "build() main render path must include 'scolta_search_index' cache tag"
    );
  }

  public function testBuildIncludesCacheKey(): void {
    $this->assertStringContainsString(
      "'#cache'",
      $this->blockContents,
      "build() must declare '#cache' metadata so Drupal knows which tags to track"
    );
  }

  // -------------------------------------------------------------------
  // show_attribution — issue scolta-php#102.
  // -------------------------------------------------------------------

  /**
   * build() must conditionally append the attribution paragraph.
   *
   * The attribution HTML must only be emitted when $config->showAttribution
   * is true — it must not be hardcoded into the default output.
   */
  public function testAttributionParagraphIsConditional(): void {
    // The block must reference the showAttribution property.
    $this->assertStringContainsString(
      'showAttribution',
      $this->blockContents,
      'build() must check $config->showAttribution before emitting attribution HTML'
    );

    // The attribution HTML string must be present in the source.
    $this->assertStringContainsString(
      'scolta-attribution',
      $this->blockContents,
      'build() must contain the scolta-attribution CSS class for the attribution paragraph'
    );

    $this->assertStringContainsString(
      'Powered by Scolta',
      $this->blockContents,
      'build() must contain "Powered by Scolta" attribution text'
    );
  }

  /**
   * The attribution HTML must be inside an if-block, not unconditional markup.
   */
  public function testAttributionIsInsideConditionalBlock(): void {
    // Verify the if($config->showAttribution) guard is present.
    $this->assertMatchesRegularExpression(
      '/if\s*\(\s*\$config->showAttribution\s*\)/',
      $this->blockContents,
      'Attribution HTML must be guarded by if ($config->showAttribution)'
    );
  }

  /**
   * The attribution paragraph must use a <p> tag with the correct class.
   */
  public function testAttributionUsesCorrectHtmlStructure(): void {
    $this->assertStringContainsString(
      '\'<p class="scolta-attribution">\' . $this->t(\'Powered by Scolta\') . \'</p>\'',
      $this->blockContents,
      'Attribution must be a <p> with class "scolta-attribution" and translatable "Powered by Scolta" text'
    );
  }

}
