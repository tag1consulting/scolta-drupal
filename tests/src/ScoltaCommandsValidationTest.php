<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The install-time Pagefind directory defaults use Drupal stream wrappers.
 *
 * Runs without a Drupal bootstrap, so this is parsed YAML, not raw source
 * text. ScoltaCommands itself cannot even be reflected in this environment
 * (its parent Drush\Commands\DrushCommands is a require-dev dependency
 * absent from the local vendor), so command registration, names, and
 * aliases are proven behaviorally by
 * \Drupal\Tests\scolta\Functional\ScoltaDrushCommandsTest.
 */
class ScoltaCommandsValidationTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  /**
   * The exact shipped defaults, so a stream-wrapper regression is caught.
   */
  public function testDefaultPagefindDirsArePublic(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    $this->assertSame('public://scolta-build', $install['pagefind']['build_dir'] ?? NULL,
      'Default install config must use public://scolta-build as build_dir');
    $this->assertSame('public://scolta-pagefind', $install['pagefind']['output_dir'] ?? NULL,
      'Default install config must use public://scolta-pagefind as output_dir');
  }

}
