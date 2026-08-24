<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that service classes use FileSystemInterface rather than raw PHP calls.
 *
 * These tests do not require a Drupal bootstrap — they verify structural
 * contracts via file inspection and reflection.
 */
class FileSystemAbstractionTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // PagefindExporter: FileSystemInterface injected and used.
  // -------------------------------------------------------------------

  public function testPagefindExporterHasFileSystemInConstructor(): void {
    $file = $this->moduleRoot . '/src/Service/PagefindExporter.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('FileSystemInterface', $contents,
      'PagefindExporter must type-hint FileSystemInterface in its constructor');
  }

  public function testPagefindExporterDeletesViaFileSystem(): void {
    $file = $this->moduleRoot . '/src/Service/PagefindExporter.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('$this->fileSystem->delete(', $contents,
      'PagefindExporter::deleteItem() must use $this->fileSystem->delete() not unlink()');
  }

  public function testPagefindExporterDeleteAllUsesFileSystem(): void {
    $file = $this->moduleRoot . '/src/Service/PagefindExporter.php';
    $contents = file_get_contents($file);
    // deleteAll() should call $this->fileSystem->delete() for each file.
    $this->assertStringContainsString('$this->fileSystem->delete($file', $contents,
      'PagefindExporter::deleteAll() must delete files via $this->fileSystem->delete()');
  }

  public function testPagefindExporterFileWriteHasPhpcsIgnore(): void {
    $file = $this->moduleRoot . '/src/Service/PagefindExporter.php';
    $lines = file($file);
    foreach ($lines as $num => $line) {
      if (str_contains($line, 'file_put_contents(')) {
        // The line or an adjacent line must have phpcs:ignore.
        $context = implode('', array_slice($lines, max(0, $num - 1), 3));
        $this->assertStringContainsString('phpcs:ignore', $context,
          "file_put_contents() on line " . ($num + 1) . " must have a phpcs:ignore comment explaining why it can't use saveData()");
        return;
      }
    }
  }

  // -------------------------------------------------------------------
  // PagefindBuilder: FileSystemInterface injected and used.
  // -------------------------------------------------------------------

  public function testPagefindBuilderHasFileSystemInConstructor(): void {
    $file = $this->moduleRoot . '/src/Service/PagefindBuilder.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('FileSystemInterface', $contents,
      'PagefindBuilder must inject FileSystemInterface in its constructor');
  }

  public function testPagefindBuilderCreatesDirectoryViaFileSystem(): void {
    $file = $this->moduleRoot . '/src/Service/PagefindBuilder.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('$this->fileSystem->mkdir(', $contents,
      'PagefindBuilder must create output directory via $this->fileSystem->mkdir()');
  }

  public function testPagefindBuilderServiceArguments(): void {
    $services = ['services' => PackageManifest::services()];
    $args = $services['services']['scolta.pagefind_builder']['arguments'] ?? [];
    $this->assertCount(3, $args,
      'scolta.pagefind_builder service must have 3 arguments (logger, file_system, index_locator)');
    $this->assertContains('@file_system', $args,
      'scolta.pagefind_builder service must inject @file_system');
    $this->assertContains('@scolta.index_locator', $args,
      'scolta.pagefind_builder must resolve index existence through the shared locator');
  }

  // -------------------------------------------------------------------
  // ScoltaRebuildWorker: FileSystemInterface used (procedural style).
  // -------------------------------------------------------------------

  public function testRebuildWorkerUsesFileSystemForMkdir(): void {
    $file = $this->moduleRoot . '/src/Plugin/QueueWorker/ScoltaRebuildWorker.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('$this->fileSystem->mkdir(', $contents,
      'ScoltaRebuildWorker must create directories via the injected $this->fileSystem->mkdir()');
  }

  public function testRebuildWorkerGetsFileSystemFromContainer(): void {
    $file = $this->moduleRoot . '/src/Plugin/QueueWorker/ScoltaRebuildWorker.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString("\$container->get('file_system')", $contents,
      "ScoltaRebuildWorker must inject file_system via create() — not \\Drupal::service()");
    $this->assertStringNotContainsString("\Drupal::service('file_system')", $contents,
      'ScoltaRebuildWorker must not fall back to the \Drupal static for file_system');
  }

  // -------------------------------------------------------------------
  // ScoltaCommands: FileSystemInterface injected and used.
  // -------------------------------------------------------------------

  public function testScoltaCommandsHasFileSystemConstructorParam(): void {
    $file = $this->moduleRoot . '/src/Commands/ScoltaCommands.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('FileSystemInterface $fileSystem', $contents,
      'ScoltaCommands constructor must accept FileSystemInterface');
  }

  public function testScoltaCommandsServiceHasFileSystemArgument(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $args = $drush['services']['scolta.commands']['arguments'] ?? [];
    $this->assertContains('@file_system', $args,
      'scolta.commands drush service must inject @file_system');
  }

  public function testScoltaCommandsUsesFileSystemMkdir(): void {
    $file = $this->moduleRoot . '/src/Commands/ScoltaCommands.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('$this->fileSystem->mkdir(', $contents,
      'ScoltaCommands must create directories via $this->fileSystem->mkdir()');
  }

  public function testScoltaCommandsUsesFileSystemChmod(): void {
    $file = $this->moduleRoot . '/src/Commands/ScoltaCommands.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('$this->fileSystem->chmod(', $contents,
      'ScoltaCommands must set permissions via $this->fileSystem->chmod()');
  }

  public function testScoltaCommandsUsesFileSystemDelete(): void {
    $file = $this->moduleRoot . '/src/Commands/ScoltaCommands.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('$this->fileSystem->delete(', $contents,
      'ScoltaCommands must delete files via $this->fileSystem->delete()');
  }

  // -------------------------------------------------------------------
  // ScoltaSettingsForm: FileSystemInterface injected and used.
  // -------------------------------------------------------------------

  public function testSettingsFormHasFileSystemProperty(): void {
    $file = $this->moduleRoot . '/src/Form/ScoltaIndexSettingsForm.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('FileSystemInterface $fileSystem', $contents,
      'ScoltaIndexSettingsForm constructor must accept FileSystemInterface');
  }

  public function testSettingsFormUsesFileSystemMkdir(): void {
    // The directories are the build's, so this is the index form's job now.
    $file = $this->moduleRoot . '/src/Form/ScoltaIndexSettingsForm.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('$this->fileSystem->mkdir(', $contents,
      'ScoltaIndexSettingsForm must create directories via $this->fileSystem->mkdir()');
  }

  public function testSettingsFormCreateGetsFileSystem(): void {
    $file = $this->moduleRoot . '/src/Form/ScoltaIndexSettingsForm.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString("'file_system'", $contents,
      "ScoltaIndexSettingsForm::create() must request 'file_system' from container");
  }

  // -------------------------------------------------------------------
  // AssetDeployer: FileSystemInterface injected and used.
  // -------------------------------------------------------------------

  public function testAssetDeployerHasFileSystemInConstructor(): void {
    $file = $this->moduleRoot . '/modules/scolta_ui/src/Service/AssetDeployer.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('FileSystemInterface', $contents,
      'AssetDeployer must type-hint FileSystemInterface in its constructor');
  }

  public function testAssetDeployerCopiesViaFileSystem(): void {
    $file = $this->moduleRoot . '/modules/scolta_ui/src/Service/AssetDeployer.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('$this->fileSystem->copy(', $contents,
      'AssetDeployer::deploy() must use $this->fileSystem->copy() not raw copy()');
  }

  public function testAssetDeployerPreparesDirectoryViaFileSystem(): void {
    $file = $this->moduleRoot . '/modules/scolta_ui/src/Service/AssetDeployer.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('$this->fileSystem->prepareDirectory(', $contents,
      'AssetDeployer::deploy() must create the destination via $this->fileSystem->prepareDirectory() not raw mkdir()');
  }

  public function testAssetDeployerImportsFileExists(): void {
    $file = $this->moduleRoot . '/modules/scolta_ui/src/Service/AssetDeployer.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('use Drupal\Core\File\FileExists;', $contents,
      'AssetDeployer must import Drupal\Core\File\FileExists for the copy() FileExists::Replace argument');
  }

  public function testAssetDeployerRemovesViaDeleteRecursive(): void {
    $file = $this->moduleRoot . '/modules/scolta_ui/src/Service/AssetDeployer.php';
    $contents = file_get_contents($file);
    $this->assertStringContainsString('$this->fileSystem->deleteRecursive(', $contents,
      'AssetDeployer::remove() must use $this->fileSystem->deleteRecursive() not raw unlink()/rmdir()');
  }

  public function testUninstallHookRemovesDeployedAssets(): void {
    // The bundle is deployed by scolta_ui_install() and removed by
    // scolta_ui_uninstall(): a backend-only site never had one.
    $file = $this->moduleRoot . '/modules/scolta_ui/scolta_ui.install';
    $contents = file_get_contents($file);
    $this->assertStringContainsString("service('scolta.asset_deployer')->remove()", $contents,
      'scolta_ui_uninstall() must remove the deployed bundle via the asset deployer');
  }

}
