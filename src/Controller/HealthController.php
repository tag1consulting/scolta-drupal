<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\ExtismCheck;

/**
 * Health check endpoint for monitoring.
 *
 * GET /api/scolta/v1/health
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

    // AI status.
    $aiConfigured = !empty($this->aiService->getApiKey());

    // Pagefind binary.
    $resolver = new PagefindBinary(
      configuredPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );
    $binaryStatus = $resolver->status();

    // WASM status.
    $wasmStatus = ExtismCheck::status();

    // Index status.
    $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $indexExists = FALSE;
    if (str_contains($outputDir, '://')) {
      try {
        $swm = \Drupal::service('stream_wrapper_manager');
        $resolvedDir = $swm->getViaUri($outputDir)->realpath() ?: $outputDir;
      }
      catch (\Exception $e) {
        $resolvedDir = $outputDir;
      }
    }
    else {
      $resolvedDir = $outputDir;
    }
    $indexExists = file_exists($resolvedDir . '/pagefind.js');

    $status = 'ok';
    if (!$indexExists || !$aiConfigured) {
      $status = 'degraded';
    }

    return new JsonResponse([
      'status' => $status,
      'ai_provider' => $this->aiService->hasDrupalAiModule() ? 'drupal-ai' : ($config->get('ai_provider') ?? 'anthropic'),
      'ai_configured' => $aiConfigured,
      'pagefind_available' => $binaryStatus['available'],
      'wasm_available' => $wasmStatus['available'],
      'index_exists' => $indexExists,
    ]);
  }

}
