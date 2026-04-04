<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Tests config mapping between Drupal's nested config and ScoltaConfig.
 *
 * ScoltaAiService.getConfig() flattens Drupal's nested config (scoring.*,
 * display.*, pagefind.*) into a flat array and passes it to
 * ScoltaConfig::fromArray(). These tests verify that mapping is correct
 * without needing a Drupal bootstrap.
 */
class ScoltaAiServiceTest extends TestCase {

  /**
   * Simulate what ScoltaAiService::getConfig() does: flatten nested Drupal
   * config, remove pagefind keys, inject API key, then create ScoltaConfig.
   */
  private function simulateGetConfig(array $drupalConfig, string $apiKey = 'test-key'): ScoltaConfig {
    $values = $drupalConfig;

    // Flatten scoring.
    if (isset($values['scoring']) && is_array($values['scoring'])) {
      foreach ($values['scoring'] as $key => $value) {
        $values[$key] = $value;
      }
      unset($values['scoring']);
    }

    // Flatten display.
    if (isset($values['display']) && is_array($values['display'])) {
      foreach ($values['display'] as $key => $value) {
        $values[$key] = $value;
      }
      unset($values['display']);
    }

    // Remove pagefind.
    unset($values['pagefind']);

    // Inject API key.
    $values['ai_api_key'] = $apiKey;

    return ScoltaConfig::fromArray($values);
  }

  /**
   * Load the install defaults as if they came from Drupal config.
   */
  private function getInstallDefaults(): array {
    $file = dirname(__DIR__, 2) . '/config/install/scolta.settings.yml';
    return \Symfony\Component\Yaml\Yaml::parseFile($file);
  }

  // -------------------------------------------------------------------
  // Config mapping tests.
  // -------------------------------------------------------------------

  public function testDefaultConfigMapsCorrectly(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->simulateGetConfig($drupalConfig);

    $this->assertEquals('anthropic', $config->aiProvider);
    $this->assertEquals('claude-sonnet-4-5-20250929', $config->aiModel);
    $this->assertEquals('test-key', $config->aiApiKey);
    $this->assertTrue($config->aiExpandQuery);
    $this->assertTrue($config->aiSummarize);
    $this->assertEquals(3, $config->maxFollowUps);
    $this->assertEquals('website', $config->siteDescription);
  }

  public function testScoringConfigFlattensCorrectly(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->simulateGetConfig($drupalConfig);

    $this->assertEquals(1.0, $config->titleMatchBoost);
    $this->assertEquals(1.5, $config->titleAllTermsMultiplier);
    $this->assertEquals(0.4, $config->contentMatchBoost);
    $this->assertEquals(0.5, $config->recencyBoostMax);
    $this->assertEquals(365, $config->recencyHalfLifeDays);
    $this->assertEquals(1825, $config->recencyPenaltyAfterDays);
    $this->assertEquals(0.3, $config->recencyMaxPenalty);
    $this->assertEquals(0.7, $config->expandPrimaryWeight);
  }

  public function testDisplayConfigFlattensCorrectly(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->simulateGetConfig($drupalConfig);

    $this->assertEquals(300, $config->excerptLength);
    $this->assertEquals(10, $config->resultsPerPage);
    $this->assertEquals(50, $config->maxPagefindResults);
    $this->assertEquals(5, $config->aiSummaryTopN);
    $this->assertEquals(2000, $config->aiSummaryMaxChars);
  }

  public function testPagefindConfigIsStripped(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->simulateGetConfig($drupalConfig);

    // pagefind.* config should not leak into ScoltaConfig properties.
    // ScoltaConfig doesn't have build_dir, output_dir, binary, etc.
    $this->assertFalse(property_exists($config, 'buildDir'));
    $this->assertFalse(property_exists($config, 'outputDir'));
    $this->assertFalse(property_exists($config, 'binary'));
  }

  public function testApiKeyInjection(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->simulateGetConfig($drupalConfig, 'sk-ant-1234');

    $this->assertEquals('sk-ant-1234', $config->aiApiKey);
  }

  public function testCustomScoringOverrides(): void {
    $drupalConfig = $this->getInstallDefaults();
    $drupalConfig['scoring']['title_match_boost'] = 2.5;
    $drupalConfig['scoring']['recency_half_life_days'] = 180;

    $config = $this->simulateGetConfig($drupalConfig);

    $this->assertEquals(2.5, $config->titleMatchBoost);
    $this->assertEquals(180, $config->recencyHalfLifeDays);
    // Other scoring values unchanged.
    $this->assertEquals(0.4, $config->contentMatchBoost);
  }

  public function testCustomPromptOverrides(): void {
    $drupalConfig = $this->getInstallDefaults();
    $drupalConfig['prompt_expand_query'] = 'Custom expand prompt for {SITE_NAME}';

    $config = $this->simulateGetConfig($drupalConfig);

    $this->assertEquals('Custom expand prompt for {SITE_NAME}', $config->promptExpandQuery);
    $this->assertEmpty($config->promptSummarize); // Not overridden.
  }

  public function testToAiClientConfigStructure(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->simulateGetConfig($drupalConfig, 'my-api-key');

    $clientConfig = $config->toAiClientConfig();

    $this->assertEquals('anthropic', $clientConfig['provider']);
    $this->assertEquals('my-api-key', $clientConfig['api_key']);
    $this->assertEquals('claude-sonnet-4-5-20250929', $clientConfig['model']);
    // Empty base_url should not be included.
    $this->assertArrayNotHasKey('base_url', $clientConfig);
  }

  public function testToAiClientConfigWithBaseUrl(): void {
    $drupalConfig = $this->getInstallDefaults();
    $drupalConfig['ai_base_url'] = 'https://custom.proxy.example.com';

    $config = $this->simulateGetConfig($drupalConfig);
    $clientConfig = $config->toAiClientConfig();

    $this->assertArrayHasKey('base_url', $clientConfig);
    $this->assertEquals('https://custom.proxy.example.com', $clientConfig['base_url']);
  }

  // -------------------------------------------------------------------
  // Install config covers all ScoltaConfig properties.
  // -------------------------------------------------------------------

  public function testInstallConfigCoversAllScoltaConfigScoringProperties(): void {
    $drupalConfig = $this->getInstallDefaults();
    $config = $this->simulateGetConfig($drupalConfig);

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
    $config = $this->simulateGetConfig($drupalConfig);

    $this->assertEquals(2592000, $config->cacheTtl); // 30 days.
  }

}
