<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
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
   * {@inheritdoc}
   */
  public function __construct(ScoltaAiService $aiService) {
    $this->aiService = $aiService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
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
        $swm = \Drupal::service('stream_wrapper_manager');
        $outputDir = $swm->getViaUri($outputDir)->realpath() ?: $outputDir;
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

    // Drupal-specific: override AI provider when Drupal AI module is active.
    if ($this->aiService->hasDrupalAiModule()) {
      $result['ai_provider'] = 'drupal-ai';
      $result['ai_configured'] = TRUE;
    }

    return new JsonResponse($result);
  }

}
