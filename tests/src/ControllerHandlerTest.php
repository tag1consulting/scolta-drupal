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
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * AiApiControllerBase::create() resolves exactly one service per parameter.
 *
 * The request pipeline itself (flood, JSON validation, response mapping) is
 * covered behaviorally by AiApiControllerPipelineTest.
 */
class ControllerHandlerTest extends TestCase {

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

}
