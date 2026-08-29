<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\scolta\Service\ScoltaAiService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies how ScoltaAiService persists gateway model resolution.
 *
 * The regression under guard is scolta-drupal#187: the onModelsResolved
 * callback used to write ai_model / ai_expansion_model — the keys an
 * administrator uses to name a provider-native model. Those hold names the
 * Amazee gateway never returns, so persisting a gateway alias there clobbered
 * an explicit choice and broke AI outright once the effective provider
 * changed. persistResolvedAmazeeModels() is executed here against a spy
 * editable config, so the guard is behavioral rather than a source grep.
 */
class ScoltaAiServiceGatewayClientTest extends TestCase {

  public function testPersistWritesOnlyTheGatewayScopedKeys(): void {
    $editable = $this->spyEditableConfig();
    $service = $this->serviceWith($editable);

    $this->invokePersist($service, 'claude-4-5-sonnet', 'claude-haiku-4-5');

    $this->assertSame(
      [
        'amazee_model' => 'claude-4-5-sonnet',
        'amazee_expansion_model' => 'claude-haiku-4-5',
      ],
      $editable->sets,
      'Resolved gateway aliases must go to the gateway-scoped keys only'
    );
    $this->assertArrayNotHasKey('ai_model', $editable->sets,
      'The operator-facing ai_model must never be written');
    $this->assertArrayNotHasKey('ai_expansion_model', $editable->sets,
      'The operator-facing ai_expansion_model must never be written');
    $this->assertSame(1, $editable->saves, 'The resolved models must be saved');
  }

  public function testPersistSkipsEmptyValues(): void {
    // An empty resolution result must not clobber a stored alias.
    $editable = $this->spyEditableConfig();
    $service = $this->serviceWith($editable);

    $this->invokePersist($service, '', '');

    $this->assertSame([], $editable->sets, 'Empty resolution results must not be persisted');
  }

  public function testPersistSkipsOnlyTheEmptyHalf(): void {
    $editable = $this->spyEditableConfig();
    $service = $this->serviceWith($editable);

    $this->invokePersist($service, 'claude-4-5-sonnet', '');

    $this->assertSame(['amazee_model' => 'claude-4-5-sonnet'], $editable->sets);
  }

  // -------------------------------------------------------------------
  // Structural: the overrides createClient() relies on.
  // -------------------------------------------------------------------

  public function testCreateClientIsOverriddenByTheAdapter(): void {
    $method = new \ReflectionMethod(ScoltaAiService::class, 'createClient');

    $this->assertSame(ScoltaAiService::class, $method->getDeclaringClass()->getName(),
      'ScoltaAiService must override createClient() to carry the model self-heal');
  }

  public function testBuildConfigIsProtected(): void {
    $method = new \ReflectionMethod(ScoltaAiService::class, 'buildConfig');

    $this->assertTrue($method->isProtected(),
      'buildConfig() must be protected so createClient() can rebuild config after a heal');
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  /**
   * Build a service whose config factory hands out the given editable spy.
   */
  private function serviceWith(object $editable): ScoltaAiService {
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('getEditable')->with('scolta.settings')->willReturn($editable);

    $ref = new \ReflectionClass(ScoltaAiService::class);
    $service = $ref->newInstanceWithoutConstructor();
    $prop = $ref->getProperty('configFactory');
    $prop->setValue($service, $configFactory);
    return $service;
  }

  /**
   * Invoke the protected persistResolvedAmazeeModels().
   */
  private function invokePersist(ScoltaAiService $service, string $model, string $expansionModel): void {
    $method = new \ReflectionMethod($service, 'persistResolvedAmazeeModels');
    $method->invoke($service, $model, $expansionModel);
  }

  /**
   * A spy standing in for the editable scolta.settings config object.
   */
  private function spyEditableConfig(): object {
    return new class() {
      public array $sets = [];
      public int $saves = 0;

      public function set(string $key, mixed $value): static {
        $this->sets[$key] = $value;
        return $this;
      }

      public function save(): static {
        $this->saves++;
        return $this;
      }

    };
  }

}
