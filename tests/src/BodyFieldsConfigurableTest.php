<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins the body-field list as configuration rather than a hardcoded array.
 *
 * ScoltaContentGatherer::buildContentItems() skips any translation where every
 * body field is empty, and that `continue` runs before
 * hook_scolta_content_item_alter(), so a hardcoded list is not something a
 * site can work around — the content never reaches a hook. With the list fixed
 * at body/field_body/field_content, every Umami recipe node fell out of the
 * index silently: the build reported "19 entities … 18 pages indexed" and
 * success, because recipes keep their prose in field_recipe_instruction.
 *
 * @group scolta
 */
class BodyFieldsConfigurableTest extends TestCase {

  private string $gathererSource;

  protected function setUp(): void {
    parent::setUp();
    $this->gathererSource = (string) file_get_contents(
      dirname(__DIR__, 2) . '/src/Service/ScoltaContentGatherer.php'
    );
  }

  /**
   * The field list comes from config, not from a literal array.
   */
  public function testBodyFieldListIsNotHardcoded(): void {
    $this->assertStringNotContainsString(
      "foreach (['body', 'field_body', 'field_content'] as \$field)",
      $this->gathererSource,
      'The body field list must come from the body_fields setting so bundles like Umami recipes can be indexed.'
    );
    $this->assertStringContainsString(
      "get('body_fields')",
      $this->gathererSource,
      'The gatherer must read the body_fields setting.'
    );
  }

  /**
   * An unset or emptied setting still indexes the historical field names.
   */
  public function testEmptySettingFallsBackToTheHistoricalDefaults(): void {
    $this->assertStringContainsString(
      "?: ['body', 'field_body', 'field_content']",
      $this->gathererSource,
      'An empty body_fields setting must fall back to the historical defaults rather than indexing nothing.'
    );
  }

  /**
   * Install default and schema both carry the key.
   */
  public function testInstallDefaultAndSchemaAgree(): void {
    $root = dirname(__DIR__, 2);

    $this->assertStringContainsString(
      'body_fields:',
      (string) file_get_contents($root . '/config/install/scolta.settings.yml'),
      'config/install must ship a body_fields default.'
    );
    $this->assertStringContainsString(
      'body_fields:',
      (string) file_get_contents($root . '/config/schema/scolta.schema.yml'),
      'config/schema must define body_fields, or config export fails validation.'
    );
    $this->assertStringContainsString(
      "set('body_fields'",
      (string) file_get_contents($root . '/src/Form/ScoltaSettingsForm.php'),
      'The settings form must persist body_fields.'
    );
  }

  /**
   * Existing sites get the key seeded by an update hook.
   */
  public function testUpdateHookSeedsExistingSites(): void {
    $install = (string) file_get_contents(dirname(__DIR__, 2) . '/scolta.install');

    $this->assertStringContainsString(
      "set('body_fields', ['body', 'field_body', 'field_content'])",
      $install,
      'An update hook must seed body_fields so an existing site indexes exactly as before.'
    );
  }

}
