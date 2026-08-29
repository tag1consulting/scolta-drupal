<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\scolta\Service\ScoltaAiService;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Tests the ScoltaAiService contract via reflection and parsed YAML.
 *
 * Verifies the class hierarchy, the constructor/service-definition agreement,
 * and getApiKey() behavior without requiring a Drupal bootstrap.
 */
class ScoltaAiServiceValidationTest extends TestCase {

  protected function tearDown(): void {
    putenv('SCOLTA_API_KEY');
  }

  public function testExtendsAiServiceAdapter(): void {
    $this->assertTrue(
      is_subclass_of(ScoltaAiService::class, AiServiceAdapter::class),
      'ScoltaAiService must extend AiServiceAdapter'
    );
  }

  public function testConstructorParameterCountMatchesServices(): void {
    $services = Yaml::parseFile(dirname(__DIR__, 2) . '/scolta.services.yml');
    $args = $services['services']['scolta.ai_service']['arguments'] ?? [];

    $constructor = new \ReflectionMethod(ScoltaAiService::class, '__construct');
    $this->assertSame(
      $constructor->getNumberOfParameters(),
      count($args),
      'ScoltaAiService constructor param count must match service arguments'
    );
  }

  public function testConstructorParameterTypes(): void {
    $constructor = new \ReflectionMethod(ScoltaAiService::class, '__construct');
    $types = [];
    foreach ($constructor->getParameters() as $param) {
      $types[$param->getName()] = $param->getType()?->getName();
    }

    $this->assertSame(ClientInterface::class, $types['httpClient'],
      'Constructor must accept the Guzzle ClientInterface');
    $this->assertSame(ConfigFactoryInterface::class, $types['configFactory'],
      'Constructor must accept ConfigFactoryInterface');
    $this->assertSame(LoggerInterface::class, $types['logger'],
      'Constructor must accept a PSR LoggerInterface');
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
