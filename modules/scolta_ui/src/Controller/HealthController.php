<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\scolta_ui\Cache\DrupalCacheDriver;
use Drupal\scolta_ui\Service\AiOrigin;
use Drupal\scolta_ui\Service\IndexOrigin;
use Drupal\scolta_ui\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\Scolta\Index\RemoteIndexLocator;

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
   * Fault key: the remote AI origin's /health did not answer.
   *
   * @since 2.0.0
   * @stability experimental
   */
  const REASON_REMOTE_AI_UNREACHABLE = 'remote_ai_unreachable';

  /**
   * Fault key: the remote AI origin's /health answered, but not 'ok'.
   *
   * That site's status covers its whole Scolta install, its index included,
   * so this says "look there", not that its AI in particular is down.
   *
   * @since 2.0.0
   * @stability experimental
   */
  const REASON_REMOTE_AI_DEGRADED = 'remote_ai_degraded';

  /**
   * Request header that marks /health being asked by another site's /health.
   *
   * Two sites that name each other as AI origin would otherwise ask each
   * other's /health on every cache miss, without end. A request carrying it
   * reports this site alone and asks no remote AI origin in turn.
   *
   * @since 2.0.0
   * @stability experimental
   */
  const PROBE_HEADER = 'X-Scolta-Health-Probe';

  /**
   * The local AI configuration faults HealthChecker can report.
   */
  private const LOCAL_AI_REASONS = [
    'ai_provider_unselected',
    'ai_key_missing',
    'ai_auth_failing',
    'ai_awaiting_amazee_model_resolution',
  ];

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
   * The HTTP client, for checking a remote AI origin.
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
   * Where AI requests are answered; NULL treats them as answered here.
   *
   * @var \Drupal\scolta_ui\Service\AiOrigin|null
   */
  protected ?AiOrigin $aiOrigin;

  /**
   * The request stack, to tell a probe from another site's /health.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|null
   */
  protected ?RequestStack $requests;

  /**
   * {@inheritdoc}
   */
  public function __construct(ScoltaAiService $aiService, IndexOrigin $indexOrigin, ClientInterface $httpClient, ?CacheBackendInterface $cache = NULL, ?AiOrigin $aiOrigin = NULL, ?RequestStack $requests = NULL) {
    $this->aiService = $aiService;
    $this->indexOrigin = $indexOrigin;
    $this->httpClient = $httpClient;
    $this->cache = $cache;
    $this->aiOrigin = $aiOrigin;
    $this->requests = $requests;
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
      $container->get('scolta_ui.ai_origin'),
      $container->get('request_stack'),
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
      // Whether the index this site searches is being served: a file check
      // for <local>, a request to the remote entry file for a URL. The block
      // renders on every page and must never pay for that request; a monitor
      // polling health can, and the locator reuses the answer for a minute.
      indexLocator: $this->indexOrigin->locator(),
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

    $result = $this->withAiOrigin($result, $cacheDriver);

    // Drupal-specific: index detail enrichment. A remote index has nothing
    // local to inspect; whether it is served is already in the checker's
    // index_exists and status_reasons.
    if ($this->indexOrigin->isRemote()) {
      $result['index_origin'] = $this->indexOrigin->remoteBase();
      $result['index'] = ['built' => $result['index_exists'], 'remote' => TRUE];
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
   * Report where AI requests are answered, and check that site when remote.
   *
   * HealthChecker judges this site's own AI configuration. On a site whose AI
   * requests another site answers, that configuration is unused and usually
   * empty, so its faults are dropped; the other site's /health is asked in
   * their place. Its answer is reused for a minute, like a remote index
   * check, because this endpoint is anonymous.
   *
   * @param array<string, mixed> $result
   *   Payload from HealthChecker::check().
   * @param \Drupal\scolta_ui\Cache\DrupalCacheDriver|null $cache
   *   Where the remote answer is reused from.
   *
   * @return array<string, mixed>
   *   The payload with `ai_origin`, and `ai_remote` when it is remote.
   */
  protected function withAiOrigin(array $result, ?DrupalCacheDriver $cache): array {
    if ($this->aiOrigin === NULL || !$this->aiOrigin->isRemote()) {
      $result['ai_origin'] = AiOrigin::LOCAL;
      return $result;
    }

    $base = (string) $this->aiOrigin->remoteBase();
    $result['ai_origin'] = $base;
    $result = self::withoutReasons($result, self::LOCAL_AI_REASONS);

    // Asked by another site's /health: answer for this site alone, so two
    // sites that name each other cannot ask each other forever.
    if ($this->requests?->getCurrentRequest()?->headers->has(self::PROBE_HEADER)) {
      $result['ai_remote'] = ['status' => 'not_checked'];
      return $result;
    }

    $status = $this->remoteAiStatus($base, $cache);
    $result['ai_remote'] = ['status' => $status];
    if ($status === 'unreachable') {
      $result = self::degradeFor($result, self::REASON_REMOTE_AI_UNREACHABLE);
    }
    elseif ($status !== 'ok') {
      $result = self::degradeFor($result, self::REASON_REMOTE_AI_DEGRADED);
    }
    return $result;
  }

  /**
   * The status a remote AI origin's /health reports, or 'unreachable'.
   *
   * Anonymous, as the browser's AI requests are, so this sees the trimmed
   * payload: the status and nothing else.
   *
   * @param string $base
   *   The remote origin, without a trailing slash.
   * @param \Drupal\scolta_ui\Cache\DrupalCacheDriver|null $cache
   *   Where the answer is reused from.
   */
  protected function remoteAiStatus(string $base, ?DrupalCacheDriver $cache): string {
    $key = 'scolta_ui:health:remote_ai:' . hash('sha256', $base);
    $cached = $cache?->get($key);
    if (is_string($cached)) {
      return $cached;
    }

    try {
      $response = $this->httpClient->request('GET', $base . '/api/scolta/v1/health', [
        'timeout' => 5,
        'connect_timeout' => 3,
        'http_errors' => FALSE,
        'headers' => [
          'Accept' => 'application/json',
          self::PROBE_HEADER => '1',
        ],
      ]);
      $payload = $response->getStatusCode() === 200
        ? json_decode((string) $response->getBody(), TRUE)
        : NULL;
      $status = is_array($payload) && is_string($payload['status'] ?? NULL)
        ? $payload['status']
        : 'unreachable';
    }
    catch (\Throwable) {
      $status = 'unreachable';
    }

    $cache?->set($key, $status, RemoteIndexLocator::DEFAULT_TTL);
    return $status;
  }

  /**
   * Remove fault keys, and report 'ok' again if none remain.
   *
   * @param array<string, mixed> $result
   *   Payload from HealthChecker::check().
   * @param string[] $reasons
   *   The fault keys to remove.
   *
   * @return array<string, mixed>
   *   The payload without them.
   */
  protected static function withoutReasons(array $result, array $reasons): array {
    if (!array_key_exists('status_reasons', $result) || !is_array($result['status_reasons'])) {
      return $result;
    }

    $result['status_reasons'] = array_values(array_diff($result['status_reasons'], $reasons));
    if ($result['status_reasons'] === [] && ($result['status'] ?? NULL) === 'degraded') {
      $result['status'] = 'ok';
    }

    return $result;
  }

}
