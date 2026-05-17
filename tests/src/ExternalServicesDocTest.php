<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that README.md contains an External Services section.
 *
 * All external HTTP connections must be documented so site administrators
 * understand what data leaves their server and under what conditions.
 */
class ExternalServicesDocTest extends TestCase {

  private string $readme;

  protected function setUp(): void {
    $path = dirname(__DIR__, 2) . '/README.md';
    $this->readme = file_get_contents($path);
  }

  // -------------------------------------------------------------------
  // Section presence
  // -------------------------------------------------------------------

  public function testExternalServicesSectionExists(): void {
    $this->assertStringContainsString(
      '## External Services',
      $this->readme,
      'README.md must contain an ## External Services section'
    );
  }

  // -------------------------------------------------------------------
  // GitHub API subsection
  // -------------------------------------------------------------------

  public function testGitHubApiSubsectionExists(): void {
    $this->assertStringContainsString(
      '### GitHub API',
      $this->readme,
      'README.md must document the GitHub API service used for Pagefind downloads'
    );
  }

  public function testGitHubApiEndpointDocumented(): void {
    $this->assertStringContainsString(
      'api.github.com',
      $this->readme,
      'README.md must include the api.github.com endpoint URL'
    );
  }

  public function testGitHubTosUrlPresent(): void {
    $this->assertStringContainsString(
      'https://docs.github.com/en/site-policy/github-terms/github-terms-of-service',
      $this->readme,
      'README.md must include the GitHub Terms of Service URL'
    );
  }

  public function testGitHubPrivacyUrlPresent(): void {
    $this->assertStringContainsString(
      'https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement',
      $this->readme,
      'README.md must include the GitHub Privacy Statement URL'
    );
  }

  // -------------------------------------------------------------------
  // Pagefind binary subsection
  // -------------------------------------------------------------------

  public function testPagefindSubsectionExists(): void {
    $this->assertStringContainsString(
      '### Pagefind',
      $this->readme,
      'README.md must document the Pagefind binary download service'
    );
  }

  public function testPagefindUrlPresent(): void {
    $this->assertStringContainsString(
      'https://pagefind.app/',
      $this->readme,
      'README.md must include the Pagefind homepage URL'
    );
  }

  public function testPagefindLicenseUrlPresent(): void {
    $this->assertStringContainsString(
      'https://github.com/Pagefind/pagefind/blob/main/LICENSE',
      $this->readme,
      'README.md must include the Pagefind license URL (Pagefind org, not CloudCannon)'
    );
  }

  // -------------------------------------------------------------------
  // AI provider subsection
  // -------------------------------------------------------------------

  public function testAiProviderSubsectionExists(): void {
    $this->assertStringContainsString(
      '### AI Provider',
      $this->readme,
      'README.md must document the AI provider APIs in the External Services section'
    );
  }

  public function testAnthropicTosUrlPresent(): void {
    $this->assertStringContainsString(
      'https://www.anthropic.com/legal/consumer-terms',
      $this->readme,
      'README.md must include the Anthropic Terms of Service URL'
    );
  }

  public function testAnthropicPrivacyUrlPresent(): void {
    $this->assertStringContainsString(
      'https://www.anthropic.com/legal/privacy',
      $this->readme,
      'README.md must include the Anthropic Privacy Policy URL'
    );
  }

  public function testOpenAiTosUrlPresent(): void {
    $this->assertStringContainsString(
      'https://openai.com/policies/terms-of-use',
      $this->readme,
      'README.md must include the OpenAI Terms of Use URL'
    );
  }

  public function testOpenAiPrivacyUrlPresent(): void {
    $this->assertStringContainsString(
      'https://openai.com/policies/privacy-policy',
      $this->readme,
      'README.md must include the OpenAI Privacy Policy URL'
    );
  }

  // -------------------------------------------------------------------
  // URL reachability (network)
  // -------------------------------------------------------------------

  /**
   * Verifies all required external service URLs return non-404 responses.
   *
   * Accepts 2xx, 3xx, and 403 (bot-blocking). Only fails on 404/410 (content
   * missing) or connection errors — those indicate a broken URL that needs
   * updating.
   *
   * @group network
   */
  public function testAllRequiredUrlsAreReachable(): void {
    if (!$this->hasNetworkAccess()) {
      $this->markTestSkipped('Network not available — skipping URL reachability checks.');
    }

    $urls = [
      'https://docs.github.com/en/site-policy/github-terms/github-terms-of-service',
      'https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement',
      'https://pagefind.app/',
      'https://cloudcannon.com/',
      'https://github.com/Pagefind/pagefind/blob/main/LICENSE',
      'https://www.anthropic.com/legal/consumer-terms',
      'https://www.anthropic.com/legal/privacy',
      'https://openai.com/policies/terms-of-use',
      'https://openai.com/policies/privacy-policy',
    ];

    $failures = [];
    foreach ($urls as $url) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; scolta-drupal-test/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
      ]);
      curl_exec($ch);
      $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $error = curl_error($ch);
      curl_close($ch);

      if ($error !== '') {
        $failures[] = "Connection error ({$error}): {$url}";
      }
      elseif (in_array($code, [404, 410], true)) {
        $failures[] = "URL not found (HTTP {$code}): {$url}";
      }
    }

    $this->assertEmpty(
      $failures,
      "The following external service URLs documented in README.md are not reachable:\n"
        . implode("\n", $failures)
    );
  }

  // -------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------

  private function hasNetworkAccess(): bool {
    $socket = @fsockopen('github.com', 443, $errno, $errstr, 3);
    if ($socket !== false) {
      fclose($socket);
      return true;
    }
    return false;
  }

}
