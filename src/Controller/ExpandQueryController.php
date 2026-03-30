<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Expands a search query into related terms using the configured AI provider.
 *
 * POST /api/scolta/v1/expand-query
 *   {"query": "product pricing"}
 *   → ["cost", "pricing plans", "rates", "subscription tiers"]
 */
class ExpandQueryController extends ControllerBase {

  public function __construct(
    private readonly ScoltaAiService $aiService,
    private readonly CacheBackendInterface $cache,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('cache.default'),
    );
  }

  public function handle(Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    $query = trim($body['query'] ?? '');

    if (empty($query) || strlen($query) > 500) {
      return new JsonResponse(['error' => 'Invalid query'], 400);
    }

    $config = $this->aiService->getConfig();

    // Cache lookup (enabled when cache_ttl > 0).
    $cacheKey = 'scolta:expand:' . hash('sha256', strtolower($query));
    if ($config->cacheTtl > 0) {
      $cached = $this->cache->get($cacheKey);
      if ($cached) {
        return new JsonResponse($cached->data);
      }
    }

    try {
      $response = $this->aiService->getClient()->message(
        $this->aiService->getExpandPrompt(),
        'Expand this search query: ' . $query,
        512,
      );

      $this->getLogger('scolta')->notice(
        'Expand raw response for "@query": @resp',
        ['@query' => $query, '@resp' => $response]
      );

      // Claude may wrap the JSON in markdown code fences — strip them.
      $cleaned = trim($response);
      $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
      $cleaned = preg_replace('/\s*```$/', '', $cleaned);
      $cleaned = trim($cleaned);

      $terms = json_decode($cleaned, TRUE);
      if (!is_array($terms) || count($terms) < 2) {
        $this->getLogger('scolta')->warning(
          'Expand failed to produce array for "@query". Parsed: @parsed',
          ['@query' => $query, '@parsed' => print_r($terms, TRUE)]
        );
        $terms = [$query];
      }

      if ($config->cacheTtl > 0) {
        $this->cache->set($cacheKey, $terms, time() + $config->cacheTtl);
      }

      return new JsonResponse($terms);
    }
    catch (\Exception $e) {
      $this->getLogger('scolta')->error(
        'Expand query failed: @msg',
        ['@msg' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Query expansion unavailable'], 503);
    }
  }

}
