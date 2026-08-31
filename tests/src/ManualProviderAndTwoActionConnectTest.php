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
 * - **No default provider.** The shipped install config selects none. While
 *   none is selected AI is off and search is unaffected.
 * - **Provenance is recorded, not guessed.** Which of the two connect actions
 *   ran drives the reported source, and none is guessed.
 */
class ManualProviderAndTwoActionConnectTest extends TestCase {

  /**
   * A key with no provider selected is not reported as a working setup.
   */
  public function testKeyWithoutAProviderResolvesAsAiOff(): void {
    $resolved = ApiKeyResolver::resolve(['env' => 'sk-env'], NULL, '');

    $this->assertFalse($resolved->providerSelected());
    $this->assertFalse($resolved->aiEnabled());
    $this->assertSame('warning', $resolved->severity());
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

}
