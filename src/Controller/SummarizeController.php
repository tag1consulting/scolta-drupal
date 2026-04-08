<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Summarizes search results using the configured AI provider.
 *
 * POST /api/scolta/v1/summarize
 *   {"query": "product pricing", "context": "...excerpts..."}
 *   -> {"summary": "Our pricing plans include..."}
 */
class SummarizeController extends ControllerBase {

  public function __construct(
    private readonly ScoltaAiService $aiService,
    private readonly CacheBackendInterface $cache,
    private readonly StateInterface $state,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('cache.default'),
      $container->get('state'),
    );
  }

  public function handle(Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    $query = trim($body['query'] ?? '');
    $context = trim($body['context'] ?? '');

    if (empty($query) || strlen($query) > 500) {
      return new JsonResponse(['error' => 'Invalid query'], 400);
    }
    if (empty($context) || strlen($context) > 50000) {
      return new JsonResponse(['error' => 'Invalid context'], 400);
    }

    $config = $this->aiService->getConfig();

    // Cache lookup with generation counter.
    $generation = $this->state->get('scolta.generation', 0);
    $cacheKey = 'scolta_summarize_' . $generation . '_' . hash('sha256', strtolower($query) . '|' . $context);
    if ($config->cacheTtl > 0) {
      $cached = $this->cache->get($cacheKey);
      if ($cached) {
        return new JsonResponse($cached->data);
      }
    }

    $userMessage = "Search query: {$query}\n\nSearch result excerpts:\n{$context}";

    try {
      $summary = $this->aiService->message(
        $this->aiService->getSummarizePrompt(),
        $userMessage,
        512,
      );

      $result = ['summary' => $summary];

      if ($config->cacheTtl > 0) {
        $this->cache->set($cacheKey, $result, time() + $config->cacheTtl, ['scolta_summarize']);
      }

      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      $this->getLogger('scolta')->error(
        'Summarize failed: @msg',
        ['@msg' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Summarization unavailable'], 503);
    }
  }

}
