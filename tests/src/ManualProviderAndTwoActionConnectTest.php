<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource;
use Tag1\Scolta\Config\AmazeeCredentials;
use Tag1\Scolta\Config\ApiKeyResolver;
use Tag1\Scolta\Config\ApiKeySource;

/**
 * Provider selection is manual, and connecting Amazee.ai takes two clicks.
 *
 * The policy this pins:
 *
 * - **No default provider.** The shipped install config selects none, the
 *   settings form preselects none, and no code path substitutes 'anthropic'
 *   for an empty value. While none is selected AI is off and search is
 *   unaffected.
 * - **Amazee is never auto-enabled.** Selecting it in the provider list
 *   connects nothing. A connection is established only by "Try the demo" (no
 *   email, no other input) or by signing in to an amazee.ai account with an
 *   email address. There is no paste-your-API-key form, matching amazee.ai's
 *   own ai_provider_amazeeio module.
 * - **Provenance is recorded, not guessed.** Which of the two actions ran is
 *   written to the credential store when it runs, so the status line states a
 *   fact.
 *
 * These are file-inspection and library-level tests — no Drupal bootstrap
 * required, matching the rest of this suite.
 */
class ManualProviderAndTwoActionConnectTest extends TestCase {

  private string $moduleRoot;
  private string $amazeeForm;
  private string $settingsForm;
  private string $aiService;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->amazeeForm = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Form/AmazeeSettingsForm.php');
    $this->settingsForm = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Form/ScoltaSettingsForm.php');
    $this->aiService = file_get_contents($this->moduleRoot . '/modules/scolta_ui/src/Service/ScoltaAiService.php');
  }

  // -------------------------------------------------------------------
  // No default provider
  // -------------------------------------------------------------------

  public function testShippedInstallConfigSelectsNoProvider(): void {
    $installed = PackageManifest::rawSettings();

    $this->assertMatchesRegularExpression(
      "/^ai_provider: ''$/m",
      $installed,
      'A fresh install must ship with no AI provider selected.',
    );
    $this->assertDoesNotMatchRegularExpression(
      '/^ai_provider: *anthropic/m',
      $installed,
      'Shipping anthropic as the install default is exactly the assumption being removed.',
    );
  }

  public function testTheProviderFieldHasNoPreselection(): void {
    // A preselected value is indistinguishable, to the operator reading the
    // form, from a choice somebody made.
    $this->assertStringContainsString("'#empty_value' => ''", $this->settingsForm);
    $this->assertStringContainsString("'#empty_option' => \$this->t('- Select a provider -')", $this->settingsForm);
  }

  public function testNoSurfaceCoalescesAnEmptyProviderToAnthropic(): void {
    $offenders = [];
    foreach ($this->providerReadingFiles() as $relative => $contents) {
      if (preg_match("/(\\?\\?|\\?:)\\s*'anthropic'/", $contents)) {
        $offenders[] = $relative;
      }
    }

    $this->assertSame(
      [],
      $offenders,
      "These files substitute 'anthropic' for an unselected provider; report the empty value instead:\n"
      . implode("\n", $offenders),
    );
  }

  /**
   * A key with no provider selected is not reported as a working setup.
   */
  public function testKeyWithoutAProviderResolvesAsAiOff(): void {
    $resolved = ApiKeyResolver::resolve(['env' => 'sk-env'], NULL, '');

    $this->assertFalse($resolved->providerSelected());
    $this->assertFalse($resolved->aiEnabled());
    $this->assertSame('warning', $resolved->severity());
  }

  // -------------------------------------------------------------------
  // Two actions, and nothing before one of them
  // -------------------------------------------------------------------

  public function testTheDemoActionSendsNoEmail(): void {
    // provision() with no argument. Trying the demo must cost an operator no
    // input at all — an email is what the account path is for.
    $this->assertMatchesRegularExpression(
      '/function submitStartTrial\(.*?\$this->trialProvisioner->provision\(\)/s',
      $this->amazeeForm,
      'The demo action must call provision() with no email.',
    );
    $this->assertDoesNotMatchRegularExpression(
      '/function submitStartTrial\(.*?provision\(\$email\)/s',
      $this->amazeeForm,
    );
  }

  public function testTheDemoButtonSkipsTheAccountEmailValidation(): void {
    // The email field belongs to the account path. Without limiting validation
    // the demo button would inherit its required-ness, which is how the form
    // came to demand an email for both actions.
    $this->assertMatchesRegularExpression(
      "/'#submit' => \[\[\\\$this, 'submitStartTrial'\]\],\s*'#limit_validation_errors' => \[\],/",
      $this->amazeeForm,
    );
  }

  public function testBothActionsArePresentedAndNeitherRunsOnItsOwn(): void {
    $this->assertStringContainsString("\$this->t('Try the demo')", $this->amazeeForm);
    $this->assertStringContainsString("\$this->t('Enter your Amazee credentials')", $this->amazeeForm);

    // Selecting the provider connects nothing: the settings form says so, and
    // sends the operator here to choose.
    $this->assertStringContainsString(
      'Selecting Amazee.ai does not connect anything on its own',
      $this->settingsForm,
    );
  }

  public function testThereIsNoManualApiKeyPath(): void {
    // Email-only, matching amazee.ai's own module: the account flow returns the
    // credentials and Scolta stores them. A paste-your-key field would be a
    // second credential scheme with nothing behind it.
    foreach (['litellm_token', 'api_key', 'API key'] as $needle) {
      $this->assertStringNotContainsString(
        "'#title' => \$this->t('{$needle}')",
        $this->amazeeForm,
        'The Amazee form must not offer a manual credential field.',
      );
    }
  }

  public function testAConsumedDemoPointsAtTheAccountPath(): void {
    // The demo is one-time. A refusal must route the operator somewhere, not
    // leave them with an API error.
    $this->assertMatchesRegularExpression(
      '/function submitStartTrial\(.*?can only be used once per site/s',
      $this->amazeeForm,
    );
  }

  public function testAnExpiredConnectionOffersTheAccountPathInPlace(): void {
    // The expiry call to action points straight at "Enter your Amazee
    // credentials" rather than making the operator disconnect first.
    $this->assertMatchesRegularExpression(
      '/isUpgradeNeeded\(\).*?Enter your Amazee credentials below.*?buildAccountSignIn\(\)/s',
      $this->amazeeForm,
    );
  }

  // -------------------------------------------------------------------
  // Provenance
  // -------------------------------------------------------------------

  public function testTheStatusLineReadsRecordedProvenance(): void {
    $this->assertStringContainsString('loadConnectionSource()', $this->amazeeForm);
    $this->assertStringContainsString('AmazeeConnectionSource::Demo', $this->amazeeForm);
    $this->assertStringContainsString('AmazeeConnectionSource::Account', $this->amazeeForm);

    // And the main settings form reports the same distinction.
    $this->assertStringContainsString('ApiKeySource::AmazeeDemo', $this->settingsForm);
    $this->assertStringContainsString('ApiKeySource::AmazeeAccount', $this->settingsForm);
  }

  public function testTheResolverIsGivenTheRecordedSourceRatherThanAGuess(): void {
    $this->assertStringContainsString('loadConnectionSource()', $this->aiService);
    $this->assertStringContainsString('connectionSource:', $this->aiService);
  }

  /**
   * Each recorded source produces its own reported source, and none is guessed.
   */
  public function testRecordedProvenanceDrivesTheReportedSource(): void {
    $cases = [
      [AmazeeConnectionSource::Demo, ApiKeySource::AmazeeDemo],
      [AmazeeConnectionSource::Account, ApiKeySource::AmazeeAccount],
      [NULL, ApiKeySource::Amazee],
    ];

    foreach ($cases as [$recorded, $expected]) {
      $resolved = ApiKeyResolver::resolve(
        [],
        AmazeeCredentials::fromArray(
          ['litellm_token' => 'tok', 'litellm_api_url' => 'https://gw.amazee.ai'],
          TRUE,
          $recorded,
        ),
        'amazee',
      );

      $this->assertSame($expected, $resolved->source);
      $this->assertTrue($resolved->source->isAmazee());
    }
  }

  public function testNoWordingClaimsAnAutomaticallyProvisionedTrial(): void {
    $offenders = [];
    foreach ($this->operatorFacingFiles() as $relative => $contents) {
      // The prefix, so 'auto-provisioning' is caught as well as
      // 'auto-provisioned' — the suffix-only list missed a live comment.
      foreach (['auto-provision', 'auto provision'] as $banned) {
        if (stripos($contents, $banned) !== FALSE) {
          $offenders[] = "{$relative}: {$banned}";
        }
      }
    }

    $this->assertSame(
      [],
      $offenders,
      "No connection is provisioned automatically, so nothing may describe one:\n"
      . implode("\n", $offenders),
    );
  }

  // -------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------

  /**
   * Files that read the configured provider and could re-introduce a default.
   *
   * @return array<string, string>
   */
  private function providerReadingFiles(): array {
    $paths = [
      'modules/scolta_ui/src/Service/ScoltaAiService.php',
      'modules/scolta_ui/src/Form/ScoltaSettingsForm.php',
      'src/Commands/ScoltaCommands.php',
    ];

    $out = [];
    foreach ($paths as $relative) {
      $out[$relative] = file_get_contents($this->moduleRoot . '/' . $relative);
    }

    return $out;
  }

  /**
   * Operator-facing sources, where stale provenance wording would surface.
   *
   * @return array<string, string>
   */
  private function operatorFacingFiles(): array {
    $out = [];
    foreach (['src/Form', 'src/Service', 'src/Commands', 'src/Controller'] as $dir) {
      $full = $this->moduleRoot . '/' . $dir;
      if (!is_dir($full)) {
        continue;
      }
      foreach (glob($full . '/*.php') as $file) {
        $out[$dir . '/' . basename($file)] = file_get_contents($file);
      }
    }

    return $out;
  }

}
