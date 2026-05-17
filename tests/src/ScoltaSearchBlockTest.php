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
    $this->blockFile = $this->moduleRoot . '/src/Plugin/Block/ScoltaSearchBlock.php';
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
      "'scolta/search'",
      $this->blockContents,
      'build() must attach scolta/search library'
    );
  }

  public function testBuildAttachesDrupalBridgeLibrary(): void {
    $this->assertStringContainsString(
      "'scolta/drupal_bridge'",
      $this->blockContents,
      'build() must attach scolta/drupal_bridge library'
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
    $endpoints = ['expand', 'summarize', 'followup'];
    foreach ($endpoints as $endpoint) {
      $this->assertStringContainsString(
        "'{$endpoint}'",
        $this->blockContents,
        "drupalSettings endpoints should include '{$endpoint}'"
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
  // WASM path — subdirectory install support.
  // -------------------------------------------------------------------

  public function testWasmPathUsesBasePath(): void {
    $this->assertStringContainsString(
      'base_path()',
      $this->blockContents,
      'wasmPath must use base_path() so subdirectory Drupal installs resolve the WASM URL correctly'
    );
  }

  public function testWasmPathDoesNotHardcodeLeadingSlash(): void {
    $this->assertStringNotContainsString(
      "= '/' . \$modulePath",
      $this->blockContents,
      'wasmPath must not use a hardcoded leading slash; use base_path() instead'
    );
  }

  public function testWasmPathConcatenatesModulePath(): void {
    // base_path() . $modulePath produces the correct path for both root
    // (base_path() = '/') and subdirectory (base_path() = '/drupal/web/')
    // installs. Verify the concatenation pattern is present.
    $this->assertStringContainsString(
      'base_path() . $modulePath',
      $this->blockContents,
      'wasmPath must concatenate base_path() with $modulePath'
    );
  }

  // -------------------------------------------------------------------
  // Cache tags — scoring config changes must invalidate cached pages.
  // -------------------------------------------------------------------

  public function testBuildDeclaresScoringConfigCacheTag(): void {
    $this->assertStringContainsString(
      "'config:scolta.settings'",
      $this->blockContents,
      "build() must include 'config:scolta.settings' cache tag so Drupal invalidates cached pages when scoring config is saved"
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
      '<p class="scolta-attribution">Powered by Scolta</p>',
      $this->blockContents,
      'Attribution must be a <p> with class "scolta-attribution" and text "Powered by Scolta"'
    );
  }

}
