<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\AiProvider\Amazee\DrupalConfigStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource;

/**
 * Behavioral tests for DrupalConfigStorage.
 *
 * Exercises store()/load(), storeConnectionSource()/loadConnectionSource(),
 * and clear() against a real (array-backed) StateInterface implementation,
 * plus the scolta.services.yml wiring and the ProvenanceAwareConfigStorageInterface
 * contract.
 */
class DrupalConfigStorageTest extends TestCase {

  protected function setUp(): void {
    // deriveKey() calls Settings::getHashSalt(), which reads the process-wide
    // singleton. Initializing it here (idempotently) is what lets store()/
    // load() run without a Drupal bootstrap. getInstance() throws (rather
    // than returning NULL) before the singleton exists.
    try {
      Settings::getInstance();
    }
    catch (\BadMethodCallException) {
      new Settings(['hash_salt' => 'test-hash-salt-for-drupalconfigstoragetest']);
    }
  }

  /**
   * A minimal array-backed StateInterface, standing in for KeyValue storage.
   */
  private function fakeState(): StateInterface {
    return new class implements StateInterface {
      private array $data = [];

      public function get($key, $default = NULL) {
        return $this->data[$key] ?? $default;
      }

      public function getMultiple(array $keys) {
        return array_intersect_key($this->data, array_flip($keys));
      }

      public function set($key, $value) {
        $this->data[$key] = $value;
      }

      public function setMultiple(array $data) {
        foreach ($data as $key => $value) {
          $this->data[$key] = $value;
        }
      }

      public function delete($key) {
        unset($this->data[$key]);
      }

      public function deleteMultiple(array $keys) {
        foreach ($keys as $key) {
          unset($this->data[$key]);
        }
      }

      public function resetCache() {}

      public function getValuesSetDuringRequest(string $key): ?array {
        return NULL;
      }
    };
  }

  // -------------------------------------------------------------------
  // Behavioral: store()/load() round-trip.
  // -------------------------------------------------------------------

  public function testStoreAndLoadRoundTrips(): void {
    $storage = new DrupalConfigStorage($this->fakeState());

    $this->assertNull($storage->load(), 'Nothing stored yet.');

    $storage->store('secret-token', 'https://litellm.example.com', 'us-east');
    $loaded = $storage->load();

    $this->assertIsArray($loaded);
    $this->assertSame('secret-token', $loaded['litellm_token'], 'The token must decrypt back to its original value.');
    $this->assertSame('https://litellm.example.com', $loaded['litellm_api_url']);
    $this->assertSame('us-east', $loaded['region']);
  }

  public function testStoreEncryptsTheTokenAtRest(): void {
    $state = $this->fakeState();
    $storage = new DrupalConfigStorage($state);

    $storage->store('super-secret-token', 'https://litellm.example.com', 'eu-west');

    $raw = $state->get('scolta.amazee.credentials');
    $this->assertIsArray($raw);
    $this->assertStringNotContainsString(
      'super-secret-token',
      $raw['litellm_token'],
      'The stored value must not contain the plaintext token.',
    );
  }

  // -------------------------------------------------------------------
  // Behavioral: storeConnectionSource()/loadConnectionSource() round-trip.
  // -------------------------------------------------------------------

  public function testConnectionSourceRoundTrips(): void {
    $storage = new DrupalConfigStorage($this->fakeState());

    $this->assertNull($storage->loadConnectionSource(), 'Nothing recorded yet.');

    $storage->storeConnectionSource(AmazeeConnectionSource::Demo);
    $this->assertSame(AmazeeConnectionSource::Demo, $storage->loadConnectionSource());

    $storage->storeConnectionSource(AmazeeConnectionSource::Account);
    $this->assertSame(AmazeeConnectionSource::Account, $storage->loadConnectionSource());
  }

  // -------------------------------------------------------------------
  // Behavioral: clear() removes both credentials and provenance.
  // -------------------------------------------------------------------

  public function testClearRemovesCredentialsAndProvenance(): void {
    $storage = new DrupalConfigStorage($this->fakeState());

    $storage->store('a-token', 'https://litellm.example.com', 'us-east');
    $storage->storeConnectionSource(AmazeeConnectionSource::Account);

    $this->assertNotNull($storage->load());
    $this->assertNotNull($storage->loadConnectionSource());

    $storage->clear();

    $this->assertNull($storage->load(), 'clear() must remove the stored credentials.');
    $this->assertNull($storage->loadConnectionSource(), 'clear() must also drop the recorded connection source.');
  }

  // -------------------------------------------------------------------
  // Structural: service wiring.
  // -------------------------------------------------------------------

  public function testServiceRegisteredWithStateArgument(): void {
    $moduleRoot = dirname(__DIR__, 2);
    $services = Yaml::parseFile($moduleRoot . '/scolta.services.yml');

    $this->assertArrayHasKey('scolta.amazee_config_storage', $services['services']);
    $definition = $services['services']['scolta.amazee_config_storage'];

    $this->assertSame(DrupalConfigStorage::class, $definition['class']);
    $this->assertSame(['@state'], $definition['arguments']);
  }

  // -------------------------------------------------------------------
  // Contract: implements ProvenanceAwareConfigStorageInterface.
  // -------------------------------------------------------------------

  public function testImplementsProvenanceAwareConfigStorageInterface(): void {
    if (!interface_exists('Tag1\Scolta\AiProvider\Amazee\ProvenanceAwareConfigStorageInterface')) {
      $this->markTestSkipped('ProvenanceAwareConfigStorageInterface is not installed.');
    }

    $this->assertContains(
      'Tag1\Scolta\AiProvider\Amazee\ProvenanceAwareConfigStorageInterface',
      class_implements(DrupalConfigStorage::class),
    );
  }

}
