<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\scolta_ui\Cache\DrupalCacheDriver;
use Drupal\scolta_ui\Service\IndexOrigin;
use Drupal\scolta_ui\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tag1\Scolta\Health\HealthChecker;

/**
 * Health check endpoint for monitoring.
 *
 * GET /api/scolta/v1/health.
 *
 * Reachable anonymously so uptime monitors always work, but anonymous
 * callers receive only the overall status. The full diagnostic payload
 * (provider, index integrity, fragment counts) requires 'administer scolta ui'.
 */
class HealthController extends ControllerBase {

  /**
   * The AI service.
   *
   * @var \Drupal\scolta_ui\Service\ScoltaAiService
   */
  protected ScoltaAiService $aiService;

  /**
   * The index locator.
   *
   * @var \Drupal\scolta_ui\Service\IndexOrigin
   */
  protected IndexOrigin $indexOrigin;

  /**
   * The HTTP client, for probing a remote index origin.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The cache backend used for KeyExpiryRecovery auth-failure markers.
   *
   * The SAME backend ScoltaAiService writes recovery markers to, so health
   * reflects whether the stored Amazee key actually authenticates.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|null
   */
  protected ?CacheBackendInterface $cache;

  /**
   * {@inheritdoc}
   */
  public function __construct(ScoltaAiService $aiService, IndexOrigin $indexOrigin, ClientInterface $httpClient, ?CacheBackendInterface $cache = NULL) {
    $this->aiService = $aiService;
    $this->indexOrigin = $indexOrigin;
    $this->httpClient = $httpClient;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('scolta_ui.index_origin'),
      $container->get('http_client'),
      $container->get('cache.default'),
    );
  }

  /**
   * Handle the health check request.
   */
  public function handle(): JsonResponse {
    $scoltaConfig = $this->aiService->getConfig();

    // The local build output, resolved through its stream wrapper. Asked of
    // the origin service rather than read here: it owns the fact that the
    // directory is scolta's to configure, and answers a sane default on a
    // site where scolta is not installed to configure it.
    $outputDir = $this->indexOrigin->resolvedOutputDir();

    // Hand HealthChecker the same cache ScoltaAiService records recovery
    // markers in, so `ai_usable` reflects whether the key still authenticates.
    $cacheDriver = $this->cache !== NULL ? new DrupalCacheDriver($this->cache) : NULL;

    $checker = new HealthChecker(
      config: $scoltaConfig,
      indexOutputDir: $outputDir,
      // A build-time key, so scolta owns it. Read by config name rather than
      // through that module: Drupal config is global, and a frontend-only
      // site simply has no binary to report on.
      pagefindBinaryPath: $this->config('scolta.settings')->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
      cache: $cacheDriver,
      // The same resolution the client performs, so /health names the key's
      // source instead of leaving a monitor to infer it (scolta-php#252).
      resolvedKey: $this->aiService->resolveApiKey(),
    );

    $result = $checker->check();

    // Drupal-specific: override AI provider only when the admin has explicitly
    // selected 'drupal_ai' AND the module is installed. Merely having the AI
    // module installed does not change routing (see ScoltaAiService), so the
    // health report must not claim it does.
    if ($scoltaConfig->aiProvider === 'drupal_ai' && $this->aiService->hasDrupalAiModule()) {
      $result['ai_provider'] = 'drupal-ai';
      $result['ai_configured'] = TRUE;
    }

    // Drupal-specific: index detail enrichment.
    //
    // A remote origin is reported as remote and reachability-checked, not
    // inspected: there is no local index to stat, and reporting "not built"
    // for a site that never builds one is a false alarm. This is the one
    // place a synchronous check of the remote index belongs — the block
    // renders on every page and must not pay for it, a monitor polling
    // health can.
    if ($this->indexOrigin->isRemote()) {
      $result['index_origin'] = $this->indexOrigin->remoteBase();
      $reachable = $this->remoteIndexReachable($this->indexOrigin->remoteBase());
      $result['index_exists'] = $reachable;
      $result['index'] = ['built' => $reachable, 'remote' => TRUE];
      if (!$reachable) {
        $result['status'] = 'degraded';
      }
      return $this->respond($result);
    }

    $result['index_origin'] = IndexOrigin::LOCAL;
    $location = $this->indexOrigin->locateLocal();
    $result['index_exists'] = $location !== NULL;
    if ($location !== NULL) {
      $indexFile = $location['indexFile'];

      $mtime = filemtime($indexFile);
      // Shares the one fragment-directory glob with countFragments().
      $fragments = $this->indexOrigin->fragmentFiles($location);

      $result['index'] = [
        'built' => TRUE,
        'fragments' => count($fragments),
        'last_build' => $mtime ? date('c', $mtime) : NULL,
      ];

      $integrity = ['valid' => TRUE, 'issues' => []];

      $jsSize = filesize($indexFile);
      if ($jsSize === FALSE || $jsSize === 0) {
        $integrity['valid'] = FALSE;
        $integrity['issues'][] = 'pagefind.js is empty or unreadable';
      }

      if (count($fragments) > 0) {
        $fragSize = filesize($fragments[0]);
        if ($fragSize === FALSE || $fragSize === 0) {
          $integrity['valid'] = FALSE;
          $integrity['issues'][] = 'Fragment file is empty or corrupt';
        }
      }
      else {
        $integrity['valid'] = FALSE;
        $integrity['issues'][] = 'No fragment files found';
      }

      $result['index']['integrity'] = $integrity;

      if (!$integrity['valid']) {
        $result['status'] = 'degraded';
      }
    }
    else {
      $result['index'] = ['built' => FALSE];
    }

    // The full report is always computed first so the trimmed status still
    // reflects integrity degradation. Callers without the admin permission
    // get exactly ['status' => ...] — enough for uptime monitors, nothing
    // an anonymous visitor shouldn't see.
    return $this->respond($result);
  }

  /**
   * Return the full report to an admin, the bare status to anyone else.
   *
   * @param array $result
   *   The assembled health report.
   */
  protected function respond(array $result): JsonResponse {
    if (!$this->currentUser()->hasPermission('administer scolta ui')) {
      return new JsonResponse(['status' => $result['status']]);
    }

    return new JsonResponse($result);
  }

  /**
   * Whether a remote index origin is serving an index right now.
   *
   * Fetches the entry file rather than the directory: a host can answer 200
   * for a directory it does not serve an index from, and the entry file is
   * the first thing the browser asks for, so this fails exactly when the
   * browser would. Short timeouts because a monitor is waiting.
   *
   * @param string $base
   *   The remote origin, without a trailing slash.
   */
  protected function remoteIndexReachable(string $base): bool {
    try {
      $response = $this->httpClient->request('GET', $base . '/pagefind/pagefind-entry.json', [
        'timeout' => 5,
        'connect_timeout' => 3,
        'http_errors' => FALSE,
      ]);
      return $response->getStatusCode() === 200;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
