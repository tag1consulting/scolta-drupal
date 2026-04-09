<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\Cache\DrupalCacheDriver;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiEndpointHandler;

/**
 * Expands a search query into related terms using the configured AI provider.
 *
 * POST /api/scolta/v1/expand-query
 *   {"query": "product pricing"}
 *   -> ["cost", "pricing plans", "rates", "subscription tiers"]
 */
class ExpandQueryController extends ControllerBase {

  public function __construct(
    private readonly ScoltaAiService $aiService,
    private readonly CacheBackendInterface $cache,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('cache.default'),
      $container->get('state'),
    );
  }

  /**
   * Handle an expand-query request.
   */
  public function handle(Request $request): JsonResponse {
    try {
        $body = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        return new JsonResponse(['error' => 'Malformed JSON: ' . $e->getMessage()], 400);
    }

    $config = $this->aiService->getConfig();
    $handler = new AiEndpointHandler(
      $this->aiService,
      $config->cacheTtl > 0 ? new DrupalCacheDriver($this->cache) : new NullCacheDriver(),
      (int) $this->state->get('scolta.generation', 0),
      $config->cacheTtl,
      $config->maxFollowUps,
    );

    $result = $handler->handleExpandQuery($body['query'] ?? '');

    if ($result['ok']) {
      return new JsonResponse($result['data']);
    }

    if (isset($result['exception'])) {
      $this->getLogger('scolta')->error(
        'Expand query failed: @msg',
        ['@msg' => $result['exception']->getMessage(), 'exception' => $result['exception']]
      );
    }

    return new JsonResponse(['error' => $result['error']], $result['status']);
  }

}
