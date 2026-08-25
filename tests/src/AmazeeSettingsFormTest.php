<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Validates AmazeeSettingsForm without a Drupal bootstrap.
 *
 * Structural tests verify class shape, imports, and wiring conventions
 * (same approach as ScoltaSettingsFormTest and YamlIntegrityTest).
 */
class AmazeeSettingsFormTest extends TestCase {

  private string $moduleRoot;
  private string $formFile;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->formFile = $this->moduleRoot . '/modules/scolta_ui/src/Form/AmazeeSettingsForm.php';
  }

  public function testFormFileExists(): void {
    $this->assertFileExists($this->formFile);
  }

  public function testExtendsFormBase(): void {
    $contents = file_get_contents($this->formFile);
    $this->assertStringContainsString('extends FormBase', $contents);
  }

  public function testImplementsStaticCreate(): void {
    $contents = file_get_contents($this->formFile);
    $this->assertStringContainsString('public static function create(ContainerInterface $container)', $contents);
  }

  public function testHasGetFormId(): void {
    $contents = file_get_contents($this->formFile);
    $this->assertStringContainsString("return 'scolta_amazee_settings'", $contents);
  }

  public function testHasRequiredSubmitHandlers(): void {
    $contents = file_get_contents($this->formFile);
    $this->assertStringContainsString('submitStartTrial', $contents, 'Trial submit handler missing');
    $this->assertStringContainsString('submitRequestCode', $contents, 'Request code submit handler missing');
    $this->assertStringContainsString('submitVerifyCode', $contents, 'Verify code submit handler missing');
    $this->assertStringContainsString('submitConnect', $contents, 'Connect submit handler missing');
    $this->assertStringContainsString('submitDisconnect', $contents, 'Disconnect submit handler missing');
    $this->assertStringContainsString('submitBack', $contents, 'Back submit handler missing');
  }

  public function testUsesCorrectNamespace(): void {
    $contents = file_get_contents($this->formFile);
    $this->assertStringContainsString("namespace Drupal\\scolta_ui\\Form;", $contents);
  }

  public function testImportsDrupalConfigStorage(): void {
    $contents = file_get_contents($this->formFile);
    $this->assertStringContainsString('use Drupal\\scolta_ui\\AiProvider\\Amazee\\DrupalConfigStorage', $contents);
  }

  public function testImportsAmazeeClasses(): void {
    $contents = file_get_contents($this->formFile);
    $this->assertStringContainsString('use Tag1\\Scolta\\AiProvider\\Amazee\\AmazeeClient', $contents);
    $this->assertStringContainsString('use Tag1\\Scolta\\AiProvider\\Amazee\\AmazeeTrialProvisioner', $contents);
    $this->assertStringContainsString('use Tag1\\Scolta\\AiProvider\\Amazee\\AmazeeAccountUpgrader', $contents);
  }

  public function testRouteExistsInRoutingYaml(): void {
    $contents = PackageManifest::raw('routing');
    $this->assertStringContainsString('scolta.settings.amazee', $contents);
    $this->assertStringContainsString('AmazeeSettingsForm', $contents);
  }

}
