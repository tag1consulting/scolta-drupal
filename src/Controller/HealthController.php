<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tag1\Scolta\Health\HealthChecker;

/**
 * Health check endpoint for monitoring.
 *
 * GET /api/scolta/v1/health.
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
   * {@inheritdoc}
   */
  public function __construct(ScoltaAiService $aiService, StreamWrapperManagerInterface $streamWrapperManager) {
    $this->aiService = $aiService;
    $this->streamWrapperManager = $streamWrapperManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('stream_wrapper_manager'),
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

    $checker = new HealthChecker(
      config: $scoltaConfig,
      indexOutputDir: $outputDir,
      pagefindBinaryPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
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
    if ($result['index_exists']) {
      // Determine the actual index file location (pagefind/ subdirectory
      // or legacy root).
      $indexFile = file_exists($outputDir . '/pagefind/pagefind.js')
        ? $outputDir . '/pagefind/pagefind.js'
        : $outputDir . '/pagefind.js';
      $fragmentDir = file_exists($outputDir . '/pagefind/pagefind.js')
        ? $outputDir . '/pagefind/fragment'
        : $outputDir . '/fragment';

      $mtime = filemtime($indexFile);
      // phpcs:ignore Drupal.Functions.DiscouragedFunctions.Discouraged -- path is already resolved from stream wrapper.
      $fragments = glob($fragmentDir . '/*') ?: [];

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

    return new JsonResponse($result);
  }

}
