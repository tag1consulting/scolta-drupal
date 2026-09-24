<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta_ui\Controller\HealthController;
use Drupal\scolta_ui\Service\IndexOrigin;
use Drupal\scolta_ui\Service\ScoltaAiService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\ApiKeySource;
use Tag1\Scolta\Config\ResolvedApiKey;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Behavioral tests for the health endpoint's permission-trimmed payload.
 *
 * Policy: the health route stays reachable anonymously so uptime monitors
 * always work, but the full diagnostic payload (provider, index integrity,
 * fragment counts) requires 'administer scolta ui'. Anonymous callers receive
 * exactly ['status' => ...].
 *
 * The real HealthController is constructed with stubbed services and a real
 * IndexOrigin over a temp directory; currentUser() and config() resolve
 * through a minimal \Drupal container installed per test.
 */
class HealthPayloadTrimTest extends TestCase {

  private string $moduleRoot;

  /**
   * Temp directory serving as the pagefind output dir.
   */
  private string $dir;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->dir = sys_get_temp_dir() . '/scolta-health-' . uniqid();
    mkdir($this->dir, 0777, TRUE);
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->dir);
  }

  // -------------------------------------------------------------------
  // Routing: the health route must be anonymously reachable.
  // -------------------------------------------------------------------

  public function testHealthRouteIsAnonymouslyReachable(): void {
    $routing = PackageManifest::routes();

    $this->assertSame(
      'TRUE',
      $routing['scolta.health']['requirements']['_access'] ?? NULL,
      'Health route must use _access TRUE so monitors work without a permission grant'
    );
    $this->assertArrayNotHasKey(
      '_permission',
      $routing['scolta.health']['requirements'],
      'Health route must not require a permission — the controller trims the payload instead'
    );
  }

  // -------------------------------------------------------------------
  // Behavioral: the controller trims by the caller's permission.
  // -------------------------------------------------------------------

  /**
   * Build a real HealthController and install its \Drupal container.
   *
   * @param bool $isAdmin
   *   Whether the current user has 'administer scolta ui'.
   * @param string|null $indexOrigin
   *   The index_origin setting; NULL searches the local index.
   * @param int $remoteStatus
   *   The HTTP status the remote origin answers its entry file with.
   */
  private function createController(bool $isAdmin, ?string $indexOrigin = NULL, int $remoteStatus = 200): HealthController {
    // One stub answers for both settings objects: the keys do not overlap.
    $settings = $this->createStub(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      // A plain filesystem path, so the stream wrapper manager is never
      // consulted and no bootstrap is needed to resolve it.
      'pagefind.output_dir' => $this->dir,
      'index_origin' => $indexOrigin,
      default => NULL,
    });
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settings);

    $account = $this->createStub(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      fn (string $permission) => $isAdmin && $permission === 'administer scolta ui'
    );

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('current_user', $account);
    \Drupal::setContainer($container);

    $aiService = $this->createStub(ScoltaAiService::class);
    $aiService->method('getConfig')->willReturn(new ScoltaConfig());
    $aiService->method('hasDrupalAiModule')->willReturn(FALSE);
    $aiService->method('resolveApiKey')->willReturn(
      new ResolvedApiKey('', ApiKeySource::None, '')
    );

    $httpClient = $this->createStub(ClientInterface::class);
    $httpClient->method('request')->willReturn(new Response($remoteStatus));

    return new HealthController(
      $aiService,
      new IndexOrigin($configFactory, $this->createStub(StreamWrapperManagerInterface::class)),
      $httpClient,
      NULL,
    );
  }

  /**
   * Write a valid index (pagefind.js plus fragments) into the temp dir.
   */
  private function buildIndex(int $fragments): void {
    mkdir($this->dir . '/pagefind/fragment', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'js');
    for ($i = 0; $i < $fragments; $i++) {
      file_put_contents($this->dir . "/pagefind/fragment/en_{$i}.pf_fragment", 'x');
    }
  }

  public function testAnonymousPayloadContainsExactlyStatus(): void {
    $this->buildIndex(2);
    $response = $this->createController(FALSE)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(['status'], array_keys($payload), 'Anonymous callers must receive exactly the status key');
    $this->assertIsString($payload['status']);
  }

  public function testAnonymousPayloadStillReflectsDegradedStatus(): void {
    // An index whose fragment directory is empty is degraded: pagefind.js
    // exists but there is nothing to search.
    mkdir($this->dir . '/pagefind', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'js');

    $response = $this->createController(FALSE)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('degraded', $payload['status'] ?? NULL, 'Integrity degradation must survive the anonymous trim');
    $this->assertArrayHasKey('status', $payload, 'Anonymous payload must contain status');
  }

  public function testAdminPayloadContainsFullDetail(): void {
    $this->buildIndex(2);
    $response = $this->createController(TRUE)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    foreach (['status', 'ai_provider', 'ai_configured', 'index_exists', 'index'] as $key) {
      $this->assertArrayHasKey($key, $payload, "Admin payload missing {$key}");
    }
    $this->assertTrue($payload['index_exists']);
    $this->assertTrue($payload['index']['built']);
    $this->assertSame(2, $payload['index']['fragments'], 'Fragment count must come from the real index on disk');
    $this->assertTrue($payload['index']['integrity']['valid']);
    $this->assertSame([], $payload['index']['integrity']['issues']);
    $this->assertNotNull($payload['index']['last_build']);
    $this->assertSame([], $payload['status_reasons'], 'A healthy index must leave status_reasons empty');
  }

  public function testAdminPayloadReportsMissingIndex(): void {
    $response = $this->createController(TRUE)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($payload['index_exists']);
    $this->assertSame(['built' => FALSE], $payload['index']);
  }

  public function testAdminPayloadFlagsEmptyFragmentDirAsInvalid(): void {
    mkdir($this->dir . '/pagefind', 0777, TRUE);
    file_put_contents($this->dir . '/pagefind/pagefind.js', 'js');

    $response = $this->createController(TRUE)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('degraded', $payload['status']);
    $this->assertFalse($payload['index']['integrity']['valid']);
    $this->assertContains('No fragment files found', $payload['index']['integrity']['issues']);
    $this->assertArrayHasKey('status_reasons', $payload);
    $this->assertContains(HealthController::REASON_INDEX_INTEGRITY_INVALID, $payload['status_reasons']);
  }

  // -------------------------------------------------------------------
  // Remote origin: the local directory's faults do not apply.
  // -------------------------------------------------------------------

  public function testRemoteOriginIsNotDegradedByTheEmptyLocalDirectory(): void {
    // Nothing is written to the local output dir: a thin consumer has none.
    $response = $this->createController(TRUE, 'https://index.example.com', 200)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('ok', $payload['status'], 'A reachable remote index must not report the missing local one');
    $this->assertNotContains('index_missing', $payload['status_reasons']);
    $this->assertSame('https://index.example.com', $payload['index_origin']);
    $this->assertSame(['built' => TRUE, 'remote' => TRUE], $payload['index']);
  }

  public function testUnreachableRemoteOriginDegradesWithItsOwnReason(): void {
    $response = $this->createController(TRUE, 'https://index.example.com', 404)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('degraded', $payload['status']);
    $this->assertSame([HealthController::REASON_REMOTE_INDEX_UNREACHABLE], $payload['status_reasons']);
    $this->assertFalse($payload['index_exists']);
  }

  public function testUnreachableRemoteOriginDegradesTheAnonymousStatusToo(): void {
    $response = $this->createController(FALSE, 'https://index.example.com', 404)->handle();

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(['status' => 'degraded'], $payload);
  }

}
