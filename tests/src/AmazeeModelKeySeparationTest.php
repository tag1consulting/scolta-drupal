<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Amazee gateway model aliases are kept out of the operator-facing model keys.
 *
 * scolta-drupal#187 / scolta-php#251. Amazee model resolution returns LiteLLM
 * **gateway aliases** (`claude-4-5-sonnet`) that only the Amazee proxy accepts.
 * They used to be written straight into `scolta.settings:ai_model` — the key an
 * administrator uses to name a provider-native model — unconditionally, so the
 * write clobbered an explicit choice as well as the shipped default. Once the
 * trial expired or a direct provider key was configured, `ai_provider` became
 * `anthropic` while `ai_model` still held an alias Anthropic does not recognise,
 * and AI degraded permanently behind a generic `ai_error`.
 *
 * Gateway aliases now live in `amazee_model` / `amazee_expansion_model`,
 * written only by resolution and read only while Amazee credentials are the
 * effective key.
 *
 * This is a structural config test — no Drupal bootstrap required. The
 * behaviour (which model actually reaches the AI client, what a resolution
 * run persists) is asserted against a real Drupal in
 * tests/src/Functional/AmazeeModelKeySeparationTest.php.
 */
class AmazeeModelKeySeparationTest extends TestCase {

  /**
   * The shipped dated default — a provider-native Anthropic ID.
   */
  private const DATED_DEFAULT = 'claude-sonnet-4-5-20250929';

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  public function testGatewayKeysAreDeclaredWithEmptyDefaults(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');

    foreach (['amazee_model', 'amazee_expansion_model'] as $key) {
      $this->assertArrayHasKey($key, $install, "{$key} must ship an install default");
      $this->assertSame('', $install[$key], "{$key} must default to empty — nothing is resolved on a fresh site");
      $this->assertSame(
        'string',
        $schema['scolta.settings']['mapping'][$key]['type'] ?? NULL,
        "{$key} must be declared as a string in the config schema"
      );
    }

    $this->assertSame(
      self::DATED_DEFAULT,
      $install['ai_model'],
      'The operator-facing default stays a provider-native Anthropic ID'
    );
  }

}
