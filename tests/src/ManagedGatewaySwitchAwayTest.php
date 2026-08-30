<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

/**
 * Nothing enables the managed gateway with an empty credential store.
 *
 * Provable without a Drupal bootstrap: the library call the request path
 * makes is inert when no connection is stored. The settings-form behavior of
 * clearing the connection on provider switch runs in
 * tests/src/Functional/ManagedGatewayOptInFunctionalTest.php.
 */
class ManagedGatewaySwitchAwayTest extends TestCase {

  /**
   * With an empty store, the gateway call makes no outbound request at all.
   *
   * The MockHandler queue is empty, so any HTTP call the library attempted
   * would fail the test rather than pass silently.
   */
  public function testNoGatewayCallIsMadeWithNothingStored(): void {
    $storage = new EmptyManagedGatewayStorage();
    $resolvedModels = NULL;

    $established = AutoProvisioner::ensureAiAvailable(
      $storage,
      hasExplicitApiKey: FALSE,
      onModelsResolved: function (string $aiModel) use (&$resolvedModels): void {
        $resolvedModels = $aiModel;
      },
      client: new AmazeeClient(
        'https://api.amazee.test',
        new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
      ),
      // Reports "unresolved", which is the state that WOULD trigger work if
      // anything were stored. Nothing is, so nothing happens.
      hasResolvedModels: fn (): bool => FALSE,
    );

    $this->assertFalse($established, 'Nothing may be established where nothing is stored');
    $this->assertNull($storage->load(), 'No connection may be written');
    $this->assertNull($resolvedModels, 'No model may be resolved');
  }

}

/**
 * A credential store that holds nothing, and records any write.
 */
class EmptyManagedGatewayStorage implements ConfigStorageInterface {

  private ?array $stored = NULL;

  public function store(string $litellmToken, string $litellmApiUrl, string $region): void {
    $this->stored = [
      'litellm_token' => $litellmToken,
      'litellm_api_url' => $litellmApiUrl,
      'region' => $region,
    ];
  }

  public function load(): ?array {
    return $this->stored;
  }

  public function clear(): void {
    $this->stored = NULL;
  }

}
