<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\SchemaCheckTestTrait;

/**
 * The shipped install defaults conform to the shipped config schema.
 *
 * This is the CLAUDE.md rule "config schema MUST match install defaults"
 * enforced by core's own typed-config validation instead of by comparing
 * YAML text: every key in config/install/scolta.settings.yml must be
 * declared, with a matching type, in config/schema/scolta.schema.yml.
 *
 * @group scolta
 */
class ConfigSchemaTest extends KernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta'];

  /**
   * The scolta.settings install defaults validate against the schema.
   */
  public function testInstallDefaultsMatchSchema(): void {
    $this->installConfig(['scolta']);
    $config = $this->config('scolta.settings');
    $this->assertNotEmpty($config->get(), 'Install defaults were imported.');
    $this->assertConfigSchema(
      $this->container->get('config.typed'),
      $config->getName(),
      $config->get()
    );
  }

}
