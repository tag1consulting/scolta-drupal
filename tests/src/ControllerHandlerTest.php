<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the AI API controller architecture via file inspection.
 *
 * The full request pipeline (flood check → parseJsonBody → handler →
 * response mapping) lives in AiApiControllerBase; the three endpoint
 * controllers implement only invokeHandler(). These tests pin that
 * structure so the ~95% triplication the base replaced cannot creep back.
 */
class ControllerHandlerTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  public static function controllerProvider(): array {
    $root = dirname(__DIR__, 2);
    return [
      'ExpandQueryController' => [
        'ExpandQueryController',
        $root . '/modules/scolta_ui/src/Controller/ExpandQueryController.php',
      ],
      'SummarizeController' => [
        'SummarizeController',
        $root . '/modules/scolta_ui/src/Controller/SummarizeController.php',
      ],
      'FollowUpController' => [
        'FollowUpController',
        $root . '/modules/scolta_ui/src/Controller/FollowUpController.php',
      ],
    ];
  }

  private function baseSource(): string {
    return file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Controller/AiApiControllerBase.php');
  }

  // -------------------------------------------------------------------
  // Base class owns the request pipeline.
  // -------------------------------------------------------------------

  public function testBaseHasHandleMethod(): void {
    $this->assertStringContainsString(
      'function handle(Request $request): JsonResponse',
      $this->baseSource(),
      'AiApiControllerBase must own handle(Request): JsonResponse'
    );
  }

  public function testBaseHasCreateMethod(): void {
    $this->assertStringContainsString(
      'public static function create(ContainerInterface $container): static',
      $this->baseSource(),
      'AiApiControllerBase must own the create() factory method'
    );
  }

  public function testBaseConstructorMatchesCreate(): void {
    $contents = $this->baseSource();

    $this->assertStringContainsString('ScoltaAiService $aiService', $contents);
    $this->assertStringContainsString('FloodInterface $flood', $contents);
    $this->assertStringContainsString('CacheBackendInterface $cache', $contents);
    $this->assertStringContainsString('StateInterface $state', $contents);

    $this->assertStringContainsString("'scolta.ai_service'", $contents);
    $this->assertStringContainsString("'flood'", $contents);
    $this->assertStringContainsString("'cache.default'", $contents);
    $this->assertStringContainsString("'state'", $contents);
  }

  public function testBaseConstructorParamCountMatchesCreateArgs(): void {
    $contents = $this->baseSource();

    if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $contents, $m)) {
      $params = array_filter(array_map('trim', explode(',', $m[1])));
      $paramCount = count($params);

      preg_match_all('/\$container->get\(/', $contents, $getMatches);
      $getCount = count($getMatches[0]);

      $this->assertEquals(
        $paramCount, $getCount,
        "AiApiControllerBase: constructor has {$paramCount} params but create() passes {$getCount} services"
      );
    }
    else {
      $this->fail('AiApiControllerBase has no constructor');
    }
  }

  public function testBaseUsesAiControllerTrait(): void {
    $this->assertStringContainsString(
      'use AiControllerTrait;',
      $this->baseSource(),
      'AiApiControllerBase should use AiControllerTrait to delegate to AiEndpointHandler'
    );
  }

  public function testBaseReturnsDataOnSuccess(): void {
    $this->assertStringContainsString(
      "return new JsonResponse(\$result['data'])",
      $this->baseSource(),
      'Success should return data from the handler result'
    );
  }

  public function testBaseForwardsErrorAndLimit(): void {
    $contents = $this->baseSource();
    $this->assertStringContainsString("'error' => \$result['error']", $contents,
      'Errors should forward the handler error');
    $this->assertStringContainsString("result['limit']", $contents,
      'Rate-limit responses should forward the remaining-limit value');
  }

  // -------------------------------------------------------------------
  // Flood control: anonymous cost-bearing endpoints fail closed.
  // -------------------------------------------------------------------

  public function testBaseChecksFloodBeforeInvokingHandler(): void {
    $contents = $this->baseSource();
    $floodPos = strpos($contents, '$this->floodAllows($request)');
    $handlerPos = strpos($contents, '$this->invokeHandler(');
    $this->assertNotFalse($floodPos, 'handle() must check flood thresholds');
    $this->assertNotFalse($handlerPos);
    $this->assertLessThan($handlerPos, $floodPos,
      'The flood check must run BEFORE any AI handler work');
    $this->assertStringContainsString('429', $contents,
      'Throttled requests must be rejected with HTTP 429');
  }

  public function testFloodChecksPerIpAndGlobalThresholds(): void {
    $contents = $this->baseSource();
    $this->assertStringContainsString("'flood.ai_ip_limit'", $contents);
    $this->assertStringContainsString("'flood.ai_global_limit'", $contents);
    $this->assertStringContainsString('->isAllowed(', $contents);
    $this->assertStringContainsString('->register(', $contents);
  }

  public function testFloodFailsClosed(): void {
    $contents = $this->baseSource();
    $this->assertMatchesRegularExpression(
      '/catch \(\\\\Throwable .*?\{.*?return FALSE;/s',
      $contents,
      'A flood-backend failure must deny the request (fail closed), not bypass rate limiting'
    );
  }

  // -------------------------------------------------------------------
  // Endpoint controllers are thin: invokeHandler() only.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testControllerExtendsBase(string $className, string $file): void {
    $this->assertStringContainsString(
      'extends AiApiControllerBase',
      file_get_contents($file),
      "{$className} must extend AiApiControllerBase"
    );
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testControllerImplementsInvokeHandler(string $className, string $file): void {
    $this->assertStringContainsString(
      'protected function invokeHandler(AiEndpointHandler $handler, array $body): array',
      file_get_contents($file),
      "{$className} must implement the single abstract invokeHandler() seam"
    );
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('controllerProvider')]
  public function testControllerDoesNotDuplicatePipeline(string $className, string $file): void {
    $contents = file_get_contents($file);
    foreach (['function handle(', 'function create(', 'json_decode(', 'resolveEnricher('] as $duplicated) {
      $this->assertStringNotContainsString(
        $duplicated,
        $contents,
        "{$className} must not duplicate '{$duplicated}' — that lives in AiApiControllerBase"
      );
    }
  }

  public function testExpandInvokesExpandQuery(): void {
    $contents = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Controller/ExpandQueryController.php');
    $this->assertStringContainsString("handleExpandQuery(\$body['query'] ?? '')", $contents);
  }

  public function testSummarizeInvokesSummarize(): void {
    $contents = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Controller/SummarizeController.php');
    $this->assertStringContainsString("handleSummarize(\$body['query'] ?? '', \$body['context'] ?? '')", $contents);
  }

  public function testFollowUpInvokesFollowUp(): void {
    $contents = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Controller/FollowUpController.php');
    $this->assertStringContainsString("handleFollowUp(\$body['messages'] ?? [])", $contents);
  }

  // -------------------------------------------------------------------
  // Caching semantics.
  // -------------------------------------------------------------------

  public function testBaseUsesCacheGeneration(): void {
    $contents = $this->baseSource();
    $this->assertStringContainsString('scolta.generation', $contents,
      'The base should use the generation counter for cache invalidation');
    $this->assertStringContainsString('cacheTtl', $contents,
      'The base should respect cacheTtl configuration');
  }

  public function testFollowUpDoesNotUseCache(): void {
    $contents = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Controller/FollowUpController.php');
    $this->assertStringNotContainsString('DrupalCacheDriver', $contents,
      'FollowUpController should not cache responses (conversations are stateful)');
    $this->assertStringContainsString('NullCacheDriver', $contents,
      'FollowUpController must override resolveCache() to never cache');
  }

  // -------------------------------------------------------------------
  // Routing wiring: each controller route exists with correct controller.
  // -------------------------------------------------------------------

  public function testRoutingMatchesControllers(): void {
    $routing = PackageManifest::routes();

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
  // Flood config ships with schema, install defaults, and form fields.
  // -------------------------------------------------------------------

  public function testFloodConfigShipsEverywhere(): void {
    $install = PackageManifest::settings();
    foreach (['ai_ip_limit', 'ai_ip_window', 'ai_global_limit', 'ai_global_window'] as $key) {
      $this->assertArrayHasKey($key, $install['flood'], "Install config missing flood.{$key}");
    }

    $schema = PackageManifest::settingsSchema();
    $floodSchema = $schema['scolta.settings']['mapping']['flood']['mapping'] ?? [];
    foreach (['ai_ip_limit', 'ai_ip_window', 'ai_global_limit', 'ai_global_window'] as $key) {
      $this->assertSame('integer', $floodSchema[$key]['type'] ?? NULL, "Schema missing flood.{$key}");
    }

    $form = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Form/ScoltaSettingsForm.php');
    foreach (['flood_ai_ip_limit', 'flood_ai_ip_window', 'flood_ai_global_limit', 'flood_ai_global_window'] as $field) {
      $this->assertStringContainsString("'{$field}'", $form, "Settings form missing {$field}");
    }
    $this->assertStringContainsString("->set('flood.ai_ip_limit'", $form);
  }

}
