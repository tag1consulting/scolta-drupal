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
 * This suite runs with no Drupal in vendor at all — CI's unit job declares
 * `provide: {drupal/core: ...}` so the framework is never installed — so the
 * assertions here are on config files and source. The behaviour they describe
 * (which model actually reaches the AI client, what a resolution run persists)
 * is asserted against a real Drupal in
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

  // -------------------------------------------------------------------------
  // The config keys themselves.
  // -------------------------------------------------------------------------

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

  /**
   * The gateway keys are deliberately not on the settings form.
   *
   * There is nothing for an administrator to choose: the names are whatever
   * the gateway's /model/info returns. A form field would recreate exactly the
   * alias-versus-native-ID confusion this fix removes.
   */
  public function testGatewayKeysAreNotOperatorSettable(): void {
    $form = file_get_contents($this->moduleRoot . '/src/Form/ScoltaSettingsForm.php');

    $this->assertStringNotContainsString('amazee_model', $form);
    $this->assertStringNotContainsString('amazee_expansion_model', $form);
  }

  // -------------------------------------------------------------------------
  // Where each binding site writes.
  // -------------------------------------------------------------------------

  /**
   * The trial-provisioning form writes gateway keys too.
   *
   * AmazeeSettingsForm::submitStartTrial() is the second binding site for the
   * same resolved names; leaving it on ai_model would reintroduce the bug for
   * anyone who starts a trial from the admin UI. Its old "only when still at
   * the shipped default" guard is dead under the new shape and must be gone —
   * there is no operator-chosen value in a gateway-scoped key to protect.
   */
  public function testTrialProvisioningFormWritesTheGatewayKeys(): void {
    $source = file_get_contents($this->moduleRoot . '/src/Form/AmazeeSettingsForm.php');

    $this->assertStringContainsString("\$config->set('amazee_model', \$result->aiModel)", $source);
    $this->assertStringContainsString("\$config->set('amazee_expansion_model', \$result->aiExpansionModel)", $source);
    $this->assertStringNotContainsString("set('ai_model'", $source, 'The trial form must not write the operator-facing ai_model');
    $this->assertStringNotContainsString("set('ai_expansion_model'", $source, 'The trial form must not write the operator-facing ai_expansion_model');
    $this->assertStringNotContainsString(
      'DEFAULT_AI_MODEL',
      $source,
      'The shipped-default guard is dead once aliases have their own key'
    );
  }

  /**
   * Nothing in the AI service may write a gateway alias to the operator keys.
   */
  public function testServiceNeverWritesTheOperatorFacingModelKeys(): void {
    $source = file_get_contents($this->moduleRoot . '/src/Service/ScoltaAiService.php');

    $this->assertDoesNotMatchRegularExpression(
      "/getEditable\('scolta\.settings'\)(?:.|\n)*?->set\('ai_(?:expansion_)?model'/",
      $source,
      'ScoltaAiService must not persist any model into the operator-facing keys'
    );
  }

  // -------------------------------------------------------------------------
  // The migration hook.
  // -------------------------------------------------------------------------

  /**
   * The update hook exists and is scoped to credentialed, unmigrated sites.
   *
   * Behaviour is asserted against a real Drupal in
   * tests/src/Functional/AmazeeModelMigrationTest.php; this pins the guards
   * that keep it from touching a site whose ai_model is an operator's own.
   */
  public function testMigrationHookIsGuarded(): void {
    $source = file_get_contents($this->moduleRoot . '/scolta.install');

    $this->assertStringContainsString('function scolta_update_10003()', $source);
    $this->assertStringContainsString("\\Drupal::state()->get('scolta.amazee.credentials')", $source);
    $this->assertStringContainsString("\$config->get('amazee_model') ?? ''", $source);
    $this->assertStringContainsString('ScoltaSettingsForm::DEFAULT_AI_MODEL', $source);
    $this->assertStringContainsString(
      "\$config->set('amazee_model', \$strandedModel)",
      $source,
      'The migration must move the alias rather than discard it'
    );
  }

}
