<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Site\Settings;
use Drupal\scolta\Service\ScoltaAiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Tests ScoltaAiService::getApiKey() behavior without a Drupal bootstrap.
 */
class ScoltaAiServiceValidationTest extends TestCase {

  protected function tearDown(): void {
    putenv('SCOLTA_API_KEY');
  }

  // -------------------------------------------------------------------
  // getApiKey() — explicit-key precedence (behavioral).
  // -------------------------------------------------------------------

  public function testGetApiKeyPrefersTheEnvironmentVariable(): void {
    new Settings(['scolta.api_key' => 'sk-from-settings']);
    putenv('SCOLTA_API_KEY=sk-from-env');

    $this->assertSame('sk-from-env', $this->bareService()->getApiKey(),
      'SCOLTA_API_KEY must win over the settings.php key');
  }

  public function testGetApiKeyFallsBackToSettings(): void {
    new Settings(['scolta.api_key' => 'sk-from-settings']);
    putenv('SCOLTA_API_KEY');

    $this->assertSame('sk-from-settings', $this->bareService()->getApiKey(),
      'Without the env var, the settings.php key applies');
  }

  public function testGetApiKeyIsEmptyWhenNothingIsConfigured(): void {
    new Settings([]);
    putenv('SCOLTA_API_KEY');

    $this->assertSame('', $this->bareService()->getApiKey());
  }

  public function testGetApiKeyTreatsANonStringSettingsValueAsUnconfigured(): void {
    // $settings['scolta.api_key'] = getenv('SCOLTA_API_KEY'); stores FALSE
    // wherever the variable is undefined. That must degrade like an absent
    // key (with a warning), never throw the TypeError that killed every Drush
    // command on the site.
    new Settings(['scolta.api_key' => FALSE]);
    putenv('SCOLTA_API_KEY');

    $logger = $this->spyLogger();
    $this->assertSame('', $this->bareService($logger)->getApiKey());
    $this->assertCount(1, $logger->records, 'The wrongly-typed key must be logged');
    $this->assertSame('warning', $logger->records[0]['level']);
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  /**
   * A service with only the logger wired — all getApiKey() needs.
   */
  private function bareService(?object $logger = NULL): ScoltaAiService {
    $ref = new \ReflectionClass(ScoltaAiService::class);
    $service = $ref->newInstanceWithoutConstructor();
    $prop = $ref->getProperty('logger');
    $prop->setValue($service, $logger ?? $this->spyLogger());
    return $service;
  }

  /**
   * A spy logger recording every record it receives.
   */
  private function spyLogger(): object {
    return new class() extends AbstractLogger {
      public array $records = [];

      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
      }

    };
  }

}
