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
 * Nothing enables the managed gateway, and switching provider removes it.
 *
 * Two halves of the opt-in rule that can be proved without a Drupal bootstrap:
 * the library call the request path makes is inert when no connection is
 * stored, and the settings form clears the connection and both recovery
 * markers when the operator selects a different provider. The behavior of the
 * second half runs in
 * tests/src/Functional/ManagedGatewayOptInFunctionalTest.php.
 */
class ManagedGatewaySwitchAwayTest extends TestCase {

  private string $formSource;

  protected function setUp(): void {
    $this->formSource = file_get_contents(dirname(__DIR__, 2) . '/src/Form/ScoltaSettingsForm.php');
  }

  // -------------------------------------------------------------------------
  // Nothing is established where nothing is stored.
  // -------------------------------------------------------------------------

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

  // -------------------------------------------------------------------------
  // Switching away removes the connection and its markers.
  // -------------------------------------------------------------------------

  public function testSubmitComparesTheProviderAgainstTheSavedOne(): void {
    $this->assertStringContainsString(
      "\$previousProvider = \$this->config('scolta.settings')->get('ai_provider');",
      $this->formSource,
      'submitForm() must read the saved provider before overwriting it'
    );
    $this->assertStringContainsString(
      "if (\$previousProvider === 'amazee' && \$form_state->getValue('ai_provider') !== 'amazee') {",
      $this->formSource,
      'The clear must fire exactly when the selection moves away from the managed gateway'
    );
  }

  public function testClearingHelperRemovesConnectionAndBothMarkers(): void {
    $body = $this->methodBody('clearManagedGatewayFootprint');

    $this->assertStringContainsString(
      '$this->amazeeConfigStorage->clear();',
      $body,
      'The stored connection must be deleted through the injected credential store'
    );
    $this->assertStringContainsString(
      '$this->aiService->clearAmazeeReauthNeeded();',
      $body,
      'The reconnect marker must be cleared with the connection it describes'
    );
    $this->assertStringContainsString(
      '$this->aiService->clearAmazeeAuthFailure();',
      $body,
      'The auth-failure marker must be cleared too, or health reports a removed connection as degraded'
    );
  }

  public function testCredentialStoreIsInjectedNotFetchedStatically(): void {
    $this->assertStringContainsString(
      "\$container->get('scolta.amazee_config_storage'),",
      $this->formSource,
      'The credential store must come from the container in create()'
    );
    $this->assertStringNotContainsString(
      "\\Drupal::service('scolta.amazee_config_storage')",
      $this->formSource,
      'The credential store must be constructor-injected, not fetched statically'
    );
  }

  // -------------------------------------------------------------------------
  // The opt-in call to action.
  // -------------------------------------------------------------------------

  public function testSettingsScreenLinksTheConnectFlowWhenNothingIsStored(): void {
    $this->assertStringContainsString(
      "if (\$this->amazeeConfigStorage->load() === NULL) {",
      $this->formSource,
      'The call to action must render only while no connection is stored'
    );
    $this->assertStringContainsString(
      "'ai_provider_amazee_connect'",
      $this->formSource,
      'The settings screen must carry a call to action for the connect step'
    );
    $this->assertMatchesRegularExpression(
      "/ai_provider_amazee_connect.*?scolta\.settings\.amazee|scolta\.settings\.amazee.*?ai_provider_amazee_connect/s",
      $this->formSource,
      'The call to action must route to the Amazee.ai settings flow'
    );
  }

  /**
   * Extracts a method body from the settings form source.
   */
  private function methodBody(string $name): string {
    $start = strpos($this->formSource, "function {$name}(");
    $this->assertNotFalse($start, "ScoltaSettingsForm must define {$name}()");

    $open = strpos($this->formSource, '{', $start);
    $end = strpos($this->formSource, "\n  }", $open);
    $this->assertNotFalse($end, "{$name}() must have a closing brace");

    return substr($this->formSource, $open, $end - $open);
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
