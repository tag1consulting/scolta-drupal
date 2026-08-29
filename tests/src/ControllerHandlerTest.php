<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\Controller\AiApiControllerBase;
use Drupal\scolta\Controller\ExpandQueryController;
use Drupal\scolta\Service\ScoltaAiService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Structural wiring tests for the AI API controllers.
 *
 * Routing and shipped-config assertions only — the request pipeline itself
 * (flood, JSON validation, response mapping) is covered behaviorally by
 * AiApiControllerPipelineTest.
 */
class ControllerHandlerTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // Routing wiring: each controller route exists with correct controller.
  // -------------------------------------------------------------------

  public function testRoutingMatchesControllers(): void {
    $routing = Yaml::parseFile($this->moduleRoot . '/scolta.routing.yml');

    $expected = [
      'scolta.expand' => 'ExpandQueryController::handle',
      'scolta.summarize' => 'SummarizeController::handle',
      'scolta.followup' => 'FollowUpController::handle',
    ];

    foreach ($expected as $routeName => $controllerMethod) {
      $this->assertArrayHasKey($routeName, $routing, "Route {$routeName} must exist");
      $controller = $routing[$routeName]['defaults']['_controller'];
      $this->assertStringContainsString($controllerMethod, $controller,
        "Route {$routeName} should reference {$controllerMethod}");
    }
  }

  // -------------------------------------------------------------------
  // create() resolves exactly one service per constructor parameter.
  // -------------------------------------------------------------------

  public function testCreateResolvesOneServicePerConstructorParameter(): void {
    $constructorParams = (new \ReflectionClass(AiApiControllerBase::class))
      ->getConstructor()->getNumberOfParameters();

    $services = [
      'scolta.ai_service' => $this->createStub(ScoltaAiService::class),
      'event_dispatcher' => $this->createStub(EventDispatcherInterface::class),
      'flood' => $this->createStub(FloodInterface::class),
      'cache.default' => $this->createStub(CacheBackendInterface::class),
      'state' => $this->createStub(StateInterface::class),
    ];
    $requested = [];
    $container = $this->createStub(ContainerInterface::class);
    $container->method('get')->willReturnCallback(
      function (string $id) use ($services, &$requested) {
        $requested[] = $id;
        $this->assertArrayHasKey($id, $services, "create() requested unexpected service '{$id}'");
        return $services[$id];
      }
    );

    // Constructing through a concrete subclass proves the argument count and
    // types line up — a mismatch is a TypeError/ArgumentCountError here.
    $controller = ExpandQueryController::create($container);

    $this->assertInstanceOf(ExpandQueryController::class, $controller);
    $this->assertCount(
      $constructorParams,
      $requested,
      'create() must resolve exactly one container service per constructor parameter'
    );
  }

  // -------------------------------------------------------------------
  // Flood config ships in both install defaults and schema.
  // -------------------------------------------------------------------

  public function testFloodConfigShipsEverywhere(): void {
    $install = Yaml::parseFile($this->moduleRoot . '/config/install/scolta.settings.yml');
    foreach (['ai_ip_limit', 'ai_ip_window', 'ai_global_limit', 'ai_global_window'] as $key) {
      $this->assertArrayHasKey($key, $install['flood'], "Install config missing flood.{$key}");
    }

    $schema = Yaml::parseFile($this->moduleRoot . '/config/schema/scolta.schema.yml');
    $floodSchema = $schema['scolta.settings']['mapping']['flood']['mapping'] ?? [];
    foreach (['ai_ip_limit', 'ai_ip_window', 'ai_global_limit', 'ai_global_window'] as $key) {
      $this->assertSame('integer', $floodSchema[$key]['type'] ?? NULL, "Schema missing flood.{$key}");
    }
  }

}
