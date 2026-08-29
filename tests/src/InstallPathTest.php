<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the install-time configuration defaults.
 */
class InstallPathTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // Default paths use Drupal stream wrappers.
  // -------------------------------------------------------------------

  public function testDefaultPathsUseDrupalStreamWrappers(): void {
    $config = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');

    $this->assertStringStartsWith(
      'public://',
      $config['pagefind']['build_dir'] ?? '',
      'build_dir must default to public:// stream wrapper for out-of-box compatibility'
    );
    $this->assertStringStartsWith(
      'public://',
      $config['pagefind']['output_dir'] ?? '',
      'output_dir must default to public:// stream wrapper'
    );
  }

}
