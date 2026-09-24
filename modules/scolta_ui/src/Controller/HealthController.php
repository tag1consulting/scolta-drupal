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
   * Fault key for an index that fails this adapter's integrity spot check.
   *
   * Covers an index that exists but is empty/corrupt (empty pagefind.js,
   * empty/corrupt or absent fragment). Named for the `index.integrity.valid`
   * field it derives from, in scolta-php's `index_*` namespace for index
   * faults, alongside `index_missing` and `index_stale_artifact_urls`.
   *
   * @since 1.4.1
   * @stability experimental
   */
  const REASON_INDEX_INTEGRITY_INVALID = 'index_integrity_invalid';

  /**
   * Fault key: the remote index origin did not serve its entry file.
   *
   * @since 2.0.0
   * @stability experimental
   */
  const REASON_REMOTE_INDEX_UNREACHABLE = 'remote_index_unreachable';

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
      $result = self::withoutLocalIndexFaults($result);
      $result['index_origin'] = $this->indexOrigin->remoteBase();
      $reachable = $this->remoteIndexReachable($this->indexOrigin->remoteBase());
      $result['index_exists'] = $reachable;
      $result['index'] = ['built' => $reachable, 'remote' => TRUE];
      if (!$reachable) {
        $result = self::degradeFor($result, self::REASON_REMOTE_INDEX_UNREACHABLE);
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
        $result = self::degradeFor($result, self::REASON_INDEX_INTEGRITY_INVALID);
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
   * Record an adapter-detected fault on a payload from HealthChecker::check().
   *
   * `status_reasons` — the checker's list of fault keys, empty exactly when
   * the status is 'ok', from scolta-php 1.5.0 — is appended to, never
   * created: the adapter cannot invent a reason vocabulary for a library that
   * reports none, so against an older scolta-php this sets the status and
   * nothing else, exactly as the flat assignment it replaces did.
   *
   * The status is raised only from 'ok'. Assigning 'degraded' outright reads
   * as a raise only while 'degraded' is the worst value there is; the moment
   * the checker gains a more severe one, that assignment demotes it and the
   * endpoint reports a healthier site than the check found. Any other value
   * is left standing, with the reason still recorded.
   *
   * @param array<string, mixed> $result
   *   Payload from HealthChecker::check(), possibly already enriched.
   * @param string $reason
   *   Machine-readable fault key, e.g. self::REASON_INDEX_INTEGRITY_INVALID.
   *
   * @return array<string, mixed>
   *   The payload with the fault recorded.
   *
   * @since 1.4.1
   * @stability experimental
   */
  public static function degradeFor(array $result, string $reason): array {
    if (array_key_exists('status_reasons', $result) && is_array($result['status_reasons'])) {
      if (!in_array($reason, $result['status_reasons'], TRUE)) {
        $result['status_reasons'][] = $reason;
      }
    }

    if (($result['status'] ?? NULL) === 'ok') {
      $result['status'] = 'degraded';
    }

    return $result;
  }

  /**
   * Strip the faults HealthChecker found in a local index directory.
   *
   * HealthChecker only knows the local output directory, so on a site whose
   * index is served from elsewhere it finds no index there and reports
   * `index_missing` (and would report stale artifact URLs from whatever a
   * past local build left behind). Neither describes the index this site
   * searches. Both are removed, and the status is recomputed from the
   * reasons that remain, before the remote index is checked in their place.
   * Against a scolta-php that reports no `status_reasons`, the status is
   * left as the checker set it apart from the index fault it cannot name.
   *
   * @param array<string, mixed> $result
   *   Payload from HealthChecker::check().
   *
   * @return array<string, mixed>
   *   The payload with local-index faults removed.
   *
   * @since 2.0.0
   * @stability experimental
   */
  public static function withoutLocalIndexFaults(array $result): array {
    $result['stale_artifact_urls'] = FALSE;
    $result['stale_artifact_message'] = NULL;

    if (!array_key_exists('status_reasons', $result) || !is_array($result['status_reasons'])) {
      return $result;
    }

    $result['status_reasons'] = array_values(array_diff(
      $result['status_reasons'],
      ['index_missing', 'index_stale_artifact_urls'],
    ));
    if ($result['status_reasons'] === [] && ($result['status'] ?? NULL) === 'degraded') {
      $result['status'] = 'ok';
    }

    return $result;
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
