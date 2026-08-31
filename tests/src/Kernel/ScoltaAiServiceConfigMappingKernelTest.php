<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\Yaml\Yaml;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Tests config mapping between Drupal's nested config and ScoltaConfig.
 *
 * ScoltaAiService::buildConfig() flattens Drupal's nested config (scoring.*,
 * display.*, pagefind.*) into a flat array and passes it to
 * ScoltaConfig::fromArray(). These tests build a real ScoltaAiService over a
 * real container and call the public getConfig() — which runs the real
 * buildConfig() once, in the constructor — rather than a hand-copied
 * reimplementation of the flattening logic. An earlier version of this file
 * carried its own simulateGetConfig() that duplicated buildConfig() by hand;
 * the two happened to agree, but nothing would have caught it if a change to
 * buildConfig() (e.g. its Amazee/explicit-key resolution, which the copy
 * skipped entirely by just injecting a literal string) had drifted from the
 * copy.
 *
 * No HTTP request is involved, so this needs only a real container —
 * KernelTestBase, not BrowserTestBase.
 *
 * @group scolta
 */
class ScoltaAiServiceConfigMappingKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta'];

  /**
   * {@inheritdoc}
   *
   * The top-level-key-precedence tests below write keys like
   * title_match_boost directly onto scolta.settings, which the schema only
   * declares nested under scoring.* and display.* — exactly the state
   * `drush config:set scolta.settings title_match_boost 3.0` produces on a
   * real site, since Drupal does not enforce config schema on save outside
   * a checked test environment. buildConfig()'s precedence logic exists
   * specifically to handle that state, so this test needs to be able to
   * create it.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('SCOLTA_API_KEY');
    parent::tearDown();
  }

  /**
   * Build a real ScoltaAiService over the given full scolta.settings state.
   *
   * $drupalConfig is the array a test built by copying and mutating the
   * install defaults, exactly as a real scolta.settings config object would
   * hold it — Config::setData() replaces the whole config with it in one
   * call. The API key reaches the client through SCOLTA_API_KEY, the highest
   * -precedence explicit-key source resolveApiKey() reads (see
   * ScoltaAiService::explicitKeyCandidates()) — this is real key resolution,
   * not injection into the config array the way the old simulation did it.
   */
  private function realGetConfig(array $drupalConfig, string $apiKey = 'test-key'): ScoltaConfig {
    $this->config('scolta.settings')->setData($drupalConfig)->save();
    putenv('SCOLTA_API_KEY=' . $apiKey);

    $service = new ScoltaAiService(
      \Drupal::httpClient(),
      \Drupal::configFactory(),
      \Drupal::logger('scolta'),
    );

    return $service->getConfig();
  }

  /**
   * Load the install defaults as if they came from Drupal config.
   */
  private function getInstallDefaults(): array {
    $file = dirname(__DIR__, 3) . '/config/install/scolta.settings.yml';
    return Yaml::parseFile($file);
  }

  // -------------------------------------------------------------------
  // Config mapping tests.
  // -------------------------------------------------------------------

  public function testDefaultConfigMapsCorrectly(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->realGetConfig($drupalConfig);

    // The shipped install defaults select no provider. AI is off on a fresh
    // install until an operator picks one, and in particular is not Anthropic.
    $this->assertSame('', $config->aiProvider);
    $this->assertEquals('claude-sonnet-4-5-20250929', $config->aiModel);
    $this->assertEquals('test-key', $config->aiApiKey);
    $this->assertTrue($config->aiExpandQuery);
    $this->assertTrue($config->aiSummarize);
    $this->assertEquals(3, $config->maxFollowUps);
    $this->assertEquals('website', $config->siteDescription);
  }

  public function testScoringConfigFlattensCorrectly(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->realGetConfig($drupalConfig);

    $this->assertEquals(2.0, $config->titleMatchBoost);
    $this->assertEquals(1.5, $config->titleAllTermsMultiplier);
    $this->assertEquals(0.4, $config->contentMatchBoost);
    $this->assertEquals(0.25, $config->recencyBoostMax);
    $this->assertEquals(365, $config->recencyHalfLifeDays);
    $this->assertEquals(1825, $config->recencyPenaltyAfterDays);
    $this->assertEquals(0.3, $config->recencyMaxPenalty);
    $this->assertEquals(0.5, $config->expandPrimaryWeight);
    $this->assertEquals(0.05, $config->crossListBonus);
    // The sub-word frequency guard setting ships in install config; the typed
    // ScoltaConfig property is provided by scolta-php once the dependency is
    // updated, so assert the install default structurally here.
    $this->assertEquals(0.05, $this->getInstallDefaults()['scoring']['expand_subword_max_frequency']);
    // The expansion combine-mode setting ships in install config (base default;
    // presets override it via scolta-php). The per-term top-K is no longer a
    // configurable setting — it is locked at 3 inside scolta-php — so it must no
    // longer appear in install config.
    $this->assertEquals('relevance_union', $this->getInstallDefaults()['scoring']['expansion_combine_mode']);
    $this->assertArrayNotHasKey('expansion_per_term_top_k', $this->getInstallDefaults()['scoring']);
    // The six specificity settings ship in install config. Their defaults must
    // match the scolta.js `??` fallbacks exactly, or a Drupal site silently
    // ranks differently from an unconfigured one. Asserted structurally, like
    // the sub-word guard above, so the check does not depend on the installed
    // scolta-php version having the typed properties.
    $scoring = $this->getInstallDefaults()['scoring'];
    $this->assertTrue($scoring['specificity_weighting']);
    $this->assertEquals(0.15, $scoring['specificity_floor']);
    $this->assertEquals(0.55, $scoring['specificity_strong_match']);
    $this->assertEquals(0.9, $scoring['specificity_cooccurrence']);
    $this->assertEquals(0.45, $scoring['specificity_agreement_gate']);
    $this->assertEquals(1.0, $scoring['specificity_agreement_decay']);
  }

  public function testDisplayConfigFlattensCorrectly(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->realGetConfig($drupalConfig);

    $this->assertEquals(300, $config->excerptLength);
    $this->assertEquals(10, $config->resultsPerPage);
    $this->assertEquals(50, $config->maxPagefindResults);
    $this->assertEquals(10, $config->aiSummaryTopN);
    $this->assertEquals(4000, $config->aiSummaryMaxChars);
  }

  public function testPagefindConfigIsStripped(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->realGetConfig($drupalConfig);

    // pagefind.* config should not leak into ScoltaConfig properties.
    // ScoltaConfig doesn't have build_dir, output_dir, binary, etc.
    $this->assertFalse(property_exists($config, 'buildDir'));
    $this->assertFalse(property_exists($config, 'outputDir'));
    $this->assertFalse(property_exists($config, 'binary'));
  }

  public function testApiKeyInjection(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->realGetConfig($drupalConfig, 'sk-ant-1234');

    $this->assertEquals('sk-ant-1234', $config->aiApiKey);
  }

  public function testCustomScoringOverrides(): void {
    $drupalConfig = $this->getInstallDefaults();
    $drupalConfig['scoring']['title_match_boost'] = 2.5;
    $drupalConfig['scoring']['recency_half_life_days'] = 180;

    $config = $this->realGetConfig($drupalConfig);

    $this->assertEquals(2.5, $config->titleMatchBoost);
    $this->assertEquals(180, $config->recencyHalfLifeDays);
    // Other scoring values unchanged.
    $this->assertEquals(0.4, $config->contentMatchBoost);
  }

  public function testCustomPromptOverrides(): void {
    $drupalConfig = $this->getInstallDefaults();
    $drupalConfig['prompt_expand_query'] = 'Custom expand prompt for {SITE_NAME}';

    $config = $this->realGetConfig($drupalConfig);

    $this->assertEquals('Custom expand prompt for {SITE_NAME}', $config->promptExpandQuery);
    $this->assertEmpty($config->promptSummarize); // Not overridden.
  }

  public function testToAiClientConfigStructure(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->realGetConfig($drupalConfig, 'my-api-key');

    $clientConfig = $config->toAiClientConfig();

    // Carried through as-is: the client config states what the site selected,
    // and an unselected provider is reported rather than filled in.
    $this->assertSame('', $clientConfig['provider']);
    $this->assertEquals('my-api-key', $clientConfig['api_key']);
    $this->assertEquals('claude-sonnet-4-5-20250929', $clientConfig['model']);
    // Empty base_url should not be included.
    $this->assertArrayNotHasKey('base_url', $clientConfig);
  }

  public function testToAiClientConfigWithBaseUrl(): void {
    $drupalConfig = $this->getInstallDefaults();
    $drupalConfig['ai_base_url'] = 'https://custom.proxy.example.com';

    $config = $this->realGetConfig($drupalConfig);
    $clientConfig = $config->toAiClientConfig();

    $this->assertArrayHasKey('base_url', $clientConfig);
    $this->assertEquals('https://custom.proxy.example.com', $clientConfig['base_url']);
  }

  // -------------------------------------------------------------------
  // Install config covers all ScoltaConfig properties.
  // -------------------------------------------------------------------

  public function testInstallConfigCoversAllScoltaConfigScoringProperties(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->realGetConfig($drupalConfig);

    // Core scoring properties should all have non-default-looking values
    // (i.e., the install config actually sets them).
    $scoringProps = [
      'titleMatchBoost', 'titleAllTermsMultiplier', 'contentMatchBoost',
      'recencyBoostMax', 'recencyHalfLifeDays', 'recencyPenaltyAfterDays',
      'recencyMaxPenalty', 'expandPrimaryWeight',
    ];

    foreach ($scoringProps as $prop) {
      $this->assertNotNull($config->$prop,
        "Scoring property {$prop} should be set by install config");
    }
  }

  public function testCacheTtlDefault(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->realGetConfig($drupalConfig);

    $this->assertEquals(2592000, $config->cacheTtl); // 30 days.
  }

  // -------------------------------------------------------------------
  // Top-level key precedence over nested display.* / scoring.* (issue #75).
  // -------------------------------------------------------------------

  /**
   * A top-level key set via drush config:set must not be overwritten by
   * display.* when both are present.
   *
   * Regression for: drush config:set scolta.settings max_pagefind_results 10
   * being silently overridden by display.max_pagefind_results.
   */
  public function testTopLevelDisplayKeyTakesPrecedenceOverNested(): void {
    $drupalConfig = $this->getInstallDefaults();
    // Simulate: drush config:set scolta.settings max_pagefind_results 10
    $drupalConfig['max_pagefind_results'] = 10;
    // display.max_pagefind_results defaults to 50 from install config.

    $config = $this->realGetConfig($drupalConfig);

    $this->assertEquals(10, $config->maxPagefindResults,
      'Top-level max_pagefind_results must override display.max_pagefind_results');
  }

  public function testTopLevelScoringKeyTakesPrecedenceOverNested(): void {
    $drupalConfig = $this->getInstallDefaults();
    // Simulate: drush config:set scolta.settings title_match_boost 3.0
    $drupalConfig['title_match_boost'] = 3.0;
    // scoring.title_match_boost defaults to 2.0.

    $config = $this->realGetConfig($drupalConfig);

    $this->assertEquals(3.0, $config->titleMatchBoost,
      'Top-level title_match_boost must override scoring.title_match_boost');
  }

  public function testNestedDisplayKeyUsedWhenNoTopLevelOverride(): void {
    $drupalConfig = $this->getInstallDefaults();
    // No top-level max_pagefind_results; display.max_pagefind_results = 50.
    $this->assertArrayNotHasKey('max_pagefind_results', $drupalConfig);

    $config = $this->realGetConfig($drupalConfig);

    $this->assertEquals(50, $config->maxPagefindResults,
      'display.max_pagefind_results must be used when no top-level key is present');
  }

  public function testTopLevelKeyUsedWhenNoNestedDisplayValue(): void {
    $drupalConfig = $this->getInstallDefaults();
    unset($drupalConfig['display']['max_pagefind_results']);
    $drupalConfig['max_pagefind_results'] = 25;

    $config = $this->realGetConfig($drupalConfig);

    $this->assertEquals(25, $config->maxPagefindResults,
      'Top-level max_pagefind_results must be used when display.* key is absent');
  }

}
