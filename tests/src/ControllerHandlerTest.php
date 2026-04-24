<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests controller handler methods via reflection and file inspection.
 *
 * Since controllers require the full Drupal framework to instantiate,
 * these tests verify structural contracts: method signatures, constructor
 * parameters matching service definitions, create() factory methods,
 * and response format expectations.
 */
class ControllerHandlerTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // All controllers have handle() with the correct signature.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testControllerHasHandleMethod(string $className, string $file): void {
    $contents = file_get_contents($file);
    $this->assertStringContainsString(
      'function handle(Request $request): JsonResponse',
      $contents,
      "{$className} must have handle(Request): JsonResponse"
    );
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testControllerHasCreateMethod(string $className, string $file): void {
    $contents = file_get_contents($file);
    $this->assertStringContainsString(
      'public static function create(ContainerInterface $container): static',
      $contents,
      "{$className} must have a create() factory method"
    );
  }

  public static function controllerProvider(): array {
    $root = dirname(__DIR__, 2);
    return [
      'ExpandQueryController' => [
        'ExpandQueryController',
        $root . '/src/Controller/ExpandQueryController.php',
      ],
      'SummarizeController' => [
        'SummarizeController',
        $root . '/src/Controller/SummarizeController.php',
      ],
      'FollowUpController' => [
        'FollowUpController',
        $root . '/src/Controller/FollowUpController.php',
      ],
    ];
  }

  // -------------------------------------------------------------------
  // Constructor parameters match the container services used in create().
  // -------------------------------------------------------------------

  public function testExpandQueryConstructorMatchesCreate(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Controller/ExpandQueryController.php');

    // Constructor should accept ScoltaAiService, CacheBackendInterface, StateInterface.
    $this->assertStringContainsString('ScoltaAiService $aiService', $contents);
    $this->assertStringContainsString('CacheBackendInterface $cache', $contents);
    $this->assertStringContainsString('StateInterface $state', $contents);

    // create() should fetch the corresponding services.
    $this->assertStringContainsString("'scolta.ai_service'", $contents);
    $this->assertStringContainsString("'cache.default'", $contents);
    $this->assertStringContainsString("'state'", $contents);
  }

  public function testSummarizeConstructorMatchesCreate(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Controller/SummarizeController.php');

    $this->assertStringContainsString('ScoltaAiService $aiService', $contents);
    $this->assertStringContainsString('CacheBackendInterface $cache', $contents);
    $this->assertStringContainsString('StateInterface $state', $contents);

    $this->assertStringContainsString("'scolta.ai_service'", $contents);
    $this->assertStringContainsString("'cache.default'", $contents);
    $this->assertStringContainsString("'state'", $contents);
  }

  public function testFollowUpConstructorMatchesCreate(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Controller/FollowUpController.php');

    // FollowUpController only needs ScoltaAiService.
    $this->assertStringContainsString('ScoltaAiService $aiService', $contents);
    $this->assertStringContainsString("'scolta.ai_service'", $contents);

    // Should NOT use cache or state (follow-ups are not cached).
    $this->assertStringNotContainsString('CacheBackendInterface', $contents);
  }

  // -------------------------------------------------------------------
  // Constructor parameter count matches create() argument count.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testConstructorParamCountMatchesCreateArgs(string $className, string $file): void {
    $contents = file_get_contents($file);

    // Count constructor parameters.
    if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $contents, $m)) {
      $params = array_filter(array_map('trim', explode(',', $m[1])));
      $paramCount = count($params);

      // Count container->get() calls in create().
      preg_match_all('/\$container->get\(/', $contents, $getMatches);
      $getCount = count($getMatches[0]);

      $this->assertEquals(
        $paramCount, $getCount,
        "{$className}: constructor has {$paramCount} params but create() passes {$getCount} services"
      );
    }
    else {
      $this->fail("{$className} has no constructor");
    }
  }

  // -------------------------------------------------------------------
  // Controllers delegate to AiEndpointHandler via AiControllerTrait.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testControllerUsesAiEndpointHandler(string $className, string $file): void {
    $contents = file_get_contents($file);
    // Controllers use AiControllerTrait which creates the handler internally.
    $this->assertStringContainsString(
      'AiControllerTrait',
      $contents,
      "{$className} should use AiControllerTrait to delegate to AiEndpointHandler"
    );
  }

  public function testExpandReturnsDataOnSuccess(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Controller/ExpandQueryController.php');
    $this->assertStringContainsString("return new JsonResponse(\$result['data'])", $contents,
      'Expand success should return data from handler result');
  }

  public function testExpandReturnsErrorOnFailure(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Controller/ExpandQueryController.php');
    $this->assertStringContainsString("'error' => \$result['error']", $contents,
      'Expand error should forward handler error');
  }

  // -------------------------------------------------------------------
  // SummarizeController response format expectations.
  // -------------------------------------------------------------------

  public function testSummarizeReturnsDataOnSuccess(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Controller/SummarizeController.php');
    $this->assertStringContainsString("return new JsonResponse(\$result['data'])", $contents,
      'Summarize success should return data from handler result');
  }

  // -------------------------------------------------------------------
  // FollowUpController response format expectations.
  // -------------------------------------------------------------------

  public function testFollowUpReturnsDataOnSuccess(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Controller/FollowUpController.php');
    $this->assertStringContainsString("return new JsonResponse(\$result['data'])", $contents,
      'FollowUp success should return data from handler result');
  }

  public function testFollowUpForwardsLimitOnRateLimit(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Controller/FollowUpController.php');
    $this->assertStringContainsString("result['limit']", $contents,
      'FollowUp should forward limit from handler on rate limit');
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
  // Controllers use caching with generation counter where appropriate.
  // -------------------------------------------------------------------

  public function testExpandAndSummarizeUseCacheGeneration(): void {
    foreach (['ExpandQueryController', 'SummarizeController'] as $name) {
      $contents = file_get_contents($this->moduleRoot . "/src/Controller/{$name}.php");
      $this->assertStringContainsString('scolta.generation', $contents,
        "{$name} should use generation counter for cache invalidation");
      $this->assertStringContainsString('cacheTtl', $contents,
        "{$name} should respect cacheTtl configuration");
    }
  }

  public function testFollowUpDoesNotUseCache(): void {
    $contents = file_get_contents($this->moduleRoot . '/src/Controller/FollowUpController.php');
    $this->assertStringNotContainsString('DrupalCacheDriver', $contents,
      'FollowUpController should not cache responses (conversations are stateful)');
  }

}
