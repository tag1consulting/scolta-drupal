<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Cache\DrupalCacheDriver;
use Drupal\scolta\Service\IndexLocator;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tag1\Scolta\Health\HealthChecker;

/**
 * Health check endpoint for monitoring.
 *
 * GET /api/scolta/v1/health.
 *
 * Reachable anonymously so uptime monitors always work, but anonymous
 * callers receive only the overall status. The full diagnostic payload
 * (provider, index integrity, fragment counts) requires 'administer scolta'.
 */
class HealthController extends ControllerBase {

  /**
   * The AI service.
   *
   * @var \Drupal\scolta\Service\ScoltaAiService
   */
  protected ScoltaAiService $aiService;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * The index locator.
   *
   * @var \Drupal\scolta\Service\IndexLocator
   */
  protected IndexLocator $indexLocator;

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
  public function __construct(ScoltaAiService $aiService, StreamWrapperManagerInterface $streamWrapperManager, IndexLocator $indexLocator, ?CacheBackendInterface $cache = NULL) {
    $this->aiService = $aiService;
    $this->streamWrapperManager = $streamWrapperManager;
    $this->indexLocator = $indexLocator;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('stream_wrapper_manager'),
      $container->get('scolta.index_locator'),
      $container->get('cache.default'),
    );
  }

  /**
   * Handle the health check request.
   */
  public function handle(): JsonResponse {
    $config = $this->config('scolta.settings');
    $scoltaConfig = $this->aiService->getConfig();

    // Resolve the index output directory (handle Drupal stream wrappers).
    $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    if (str_contains($outputDir, '://')) {
      try {
        $outputDir = $this->streamWrapperManager
          ->getViaUri($outputDir)->realpath() ?: $outputDir;
      }
      catch (\Exception $e) {
        // Fall through with original path.
      }
    }

    // Hand HealthChecker the same cache ScoltaAiService records recovery
    // markers in, so `ai_usable` reflects whether the key still authenticates.
    $cacheDriver = $this->cache !== NULL ? new DrupalCacheDriver($this->cache) : NULL;

    $checker = new HealthChecker(
      config: $scoltaConfig,
      indexOutputDir: $outputDir,
      pagefindBinaryPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
      cache: $cacheDriver,
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

    // Drupal-specific: index detail enrichment. The shared locator decides
    // what "index exists" means (modern pagefind/ layout or legacy root).
    $location = $this->indexLocator->locate($outputDir);
    $result['index_exists'] = $location !== NULL;
    if ($location !== NULL) {
      $indexFile = $location['indexFile'];

      $mtime = filemtime($indexFile);
      // Shares the one fragment-directory glob with countFragments().
      $fragments = $this->indexLocator->fragmentFiles($location);

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
    if (!$this->currentUser()->hasPermission('administer scolta')) {
      return new JsonResponse(['status' => $result['status']]);
    }

    return new JsonResponse($result);
  }

}
