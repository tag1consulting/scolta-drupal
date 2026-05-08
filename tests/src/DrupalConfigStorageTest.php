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
    $this->storageFile = $this->moduleRoot . '/src/AiProvider/Amazee/DrupalConfigStorage.php';
  }

  public function testFileExists(): void {
    $this->assertFileExists($this->storageFile);
  }

  public function testDeclaresStrictTypes(): void {
    $contents = file_get_contents($this->storageFile);
    $this->assertStringContainsString('declare(strict_types=1)', $contents);
  }

  public function testImplementsConfigStorageInterface(): void {
    $contents = file_get_contents($this->storageFile);
    $this->assertStringContainsString('implements ConfigStorageInterface', $contents);
  }

  public function testImportsConfigStorageInterface(): void {
    $contents = file_get_contents($this->storageFile);
    $this->assertStringContainsString(
      'use Tag1\\Scolta\\AiProvider\\Amazee\\ConfigStorageInterface',
      $contents,
    );
    // The interface class must exist in the installed scolta-php vendor copy.
    $interfaceFile = $this->moduleRoot . '/vendor/tag1/scolta-php/src/AiProvider/Amazee/ConfigStorageInterface.php';
    $this->assertFileExists($interfaceFile, 'ConfigStorageInterface must exist in installed tag1/scolta-php');
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
    $yaml = file_get_contents($this->moduleRoot . '/scolta.services.yml');
    $this->assertStringContainsString('scolta.amazee_config_storage', $yaml);
    $this->assertStringContainsString('DrupalConfigStorage', $yaml);
    $this->assertStringContainsString("'@state'", $yaml);
  }

}
