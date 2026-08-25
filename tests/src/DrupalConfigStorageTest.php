<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

/**
 * Validates DrupalConfigStorage without a Drupal bootstrap.
 *
 * Uses source-level checks (the same pattern as StructuralIntegrityTest)
 * because Drupal\Core\State\StateInterface is not available without a
 * Drupal bootstrap.
 */
class DrupalConfigStorageTest extends TestCase {

  private string $moduleRoot;
  private string $storageFile;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->storageFile = $this->moduleRoot . '/modules/scolta_ui/src/AiProvider/Amazee/DrupalConfigStorage.php';
  }

  public function testFileExists(): void {
    $this->assertFileExists($this->storageFile);
  }

  public function testDeclaresStrictTypes(): void {
    $contents = file_get_contents($this->storageFile);
    $this->assertStringContainsString('declare(strict_types=1)', $contents);
  }

  public function testImplementsProvenanceAwareConfigStorageInterface(): void {
    // The provenance-aware sub-interface extends ConfigStorageInterface, so
    // this is still the credential store contract — with somewhere to record
    // which operator action established the connection, so no surface has to
    // guess between the demo and the operator's own account.
    $contents = file_get_contents($this->storageFile);
    $this->assertStringContainsString('implements ProvenanceAwareConfigStorageInterface', $contents);
  }

  public function testImportsProvenanceAwareConfigStorageInterface(): void {
    $contents = file_get_contents($this->storageFile);
    $this->assertStringContainsString(
      'use Tag1\\Scolta\\AiProvider\\Amazee\\ProvenanceAwareConfigStorageInterface',
      $contents,
    );
    // Both interfaces must exist in the installed scolta-php vendor copy.
    $vendorAmazee = $this->moduleRoot . '/vendor/tag1/scolta-php/src/AiProvider/Amazee/';
    $this->assertFileExists($vendorAmazee . 'ConfigStorageInterface.php', 'ConfigStorageInterface must exist in installed tag1/scolta-php');
    $this->assertFileExists($vendorAmazee . 'ProvenanceAwareConfigStorageInterface.php', 'ProvenanceAwareConfigStorageInterface must exist in installed tag1/scolta-php');
  }

  public function testRecordsAndClearsTheConnectionSource(): void {
    $contents = file_get_contents($this->storageFile);
    $this->assertStringContainsString('public function storeConnectionSource(', $contents);
    $this->assertStringContainsString('public function loadConnectionSource(', $contents);
    // Clearing credentials must drop the provenance with them: a stale record
    // would be paired with whatever connection comes next.
    $this->assertMatchesRegularExpression(
      '/function clear\(\).*SOURCE_STATE_KEY/s',
      $contents,
      'clear() must delete the recorded connection source',
    );
  }

  public function testHasRequiredMethods(): void {
    $contents = file_get_contents($this->storageFile);
    $this->assertStringContainsString('public function store(', $contents, 'store() method missing');
    $this->assertStringContainsString('public function load(', $contents, 'load() method missing');
    $this->assertStringContainsString('public function clear(', $contents, 'clear() method missing');
  }

  public function testUsesStateKey(): void {
    $contents = file_get_contents($this->storageFile);
    $this->assertStringContainsString('scolta.amazee.credentials', $contents);
  }

  public function testInjectsStateInterface(): void {
    $contents = file_get_contents($this->storageFile);
    $this->assertStringContainsString('StateInterface', $contents);
    $this->assertStringContainsString('use Drupal\Core\State\StateInterface', $contents);
  }

  public function testServiceRegistered(): void {
    $yaml = PackageManifest::raw('services');
    $this->assertStringContainsString('scolta.amazee_config_storage', $yaml);
    $this->assertStringContainsString('DrupalConfigStorage', $yaml);
    $this->assertStringContainsString("'@state'", $yaml);
  }

}
