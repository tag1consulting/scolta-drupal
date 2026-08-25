<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Nothing outside ScoltaAiService::resolveApiKey() decides where the key came from.
 *
 * The defect was two derivations of one fact with opposite precedence:
 * buildConfig() preferred an explicit env/settings.php key, getApiKeySource()
 * checked Amazee first. Making them agree would have left them free to drift
 * again, so what this pins is the structural property — one decision point.
 *
 * @see https://github.com/tag1consulting/scolta-php/issues/252
 */
class ApiKeySourceSingleDerivationTest extends TestCase {

  /**
   * Files allowed to name a source or read the credential store directly.
   *
   * ScoltaAiService holds resolveApiKey(); DrupalConfigStorage is the
   * credential store itself; the settings form maps resolved sources onto
   * translated messages, which is deriving from the resolution rather than
   * making one.
   */
  private const ALLOWED = [
    'modules/scolta_ui/src/Service/ScoltaAiService.php',
    'modules/scolta_ui/src/AiProvider/Amazee/DrupalConfigStorage.php',
    'modules/scolta_ui/src/Form/ScoltaSettingsForm.php',
  ];

  /**
   * No file reads the Amazee credential state to answer "which key is in use".
   */
  public function testOnlyTheResolverAndTheStoreReadStoredCredentials(): void {
    $offenders = [];
    foreach ($this->moduleFiles() as $relative => $contents) {
      if (in_array($relative, self::ALLOWED, TRUE)) {
        continue;
      }
      if (str_contains($contents, 'scolta.amazee.credentials')
        || str_contains($contents, 'litellm_token')) {
        $offenders[] = $relative;
      }
    }

    $this->assertSame(
      [],
      $offenders,
      "Reading the credential store outside the resolver is how a surface ends up reporting Amazee "
      . "as active when an explicit key won:\n" . implode("\n", $offenders)
    );
  }

  /**
   * No file reads SCOLTA_API_KEY to work out a source of its own.
   */
  public function testOnlyTheResolverReadsTheEnvironmentVariable(): void {
    $offenders = [];
    foreach ($this->moduleFiles() as $relative => $contents) {
      if (in_array($relative, self::ALLOWED, TRUE)) {
        continue;
      }
      if (str_contains($contents, "getenv('SCOLTA_API_KEY')")) {
        $offenders[] = $relative;
      }
    }

    $this->assertSame(
      [],
      $offenders,
      "Take the key and its source from ScoltaAiService::resolveApiKey():\n" . implode("\n", $offenders)
    );
  }

  /**
   * The reporting surfaces call the resolver rather than re-deriving.
   */
  public function testEveryReportingSurfaceDerivesFromTheResolution(): void {
    $surfaces = [
      'modules/scolta_ui/src/Form/ScoltaSettingsForm.php' => 'the settings form',
      'modules/scolta_ui/src/Controller/HealthController.php' => 'the health payload',
      'modules/scolta_ui/src/Commands/ScoltaUiCommands.php' => 'the Drush ai-status and check-setup commands',
    ];

    foreach ($surfaces as $file => $label) {
      $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
      $this->assertStringContainsString(
        'resolveApiKey()',
        $contents,
        sprintf('%s must report from the shared resolution', $label)
      );
    }
  }

  /**
   * Read every PHP source file in the module.
   *
   * @return array<string, string>
   *   Relative path => contents.
   */
  private function moduleFiles(): array {
    $files = PackageManifest::sourceFiles();
    $this->assertNotEmpty($files, 'Found no module sources to scan');

    return $files;
  }

}
