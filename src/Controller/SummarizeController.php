<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\Cache\DrupalCacheDriver;
use Drupal\scolta\Prompt\EventDrivenEnricher;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiEndpointHandler;

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
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('cache.default'),
      $container->get('state'),
      $container->get('event_dispatcher'),
    );
  }

  /**
   * Handle a summarize request.
   */
  public function handle(Request $request): JsonResponse {
    try {
      $body = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      return new JsonResponse(['error' => 'Malformed JSON: ' . $e->getMessage()], 400);
    }

    $config = $this->aiService->getConfig();
    $handler = new AiEndpointHandler(
      $this->aiService,
      $config->cacheTtl > 0 ? new DrupalCacheDriver($this->cache) : new NullCacheDriver(),
      (int) $this->state->get('scolta.generation', 0),
      $config->cacheTtl,
      $config->maxFollowUps,
      new EventDrivenEnricher($this->eventDispatcher),
      $config->aiLanguages,
    );

    $result = $handler->handleSummarize($body['query'] ?? '', $body['context'] ?? '');

    if ($result['ok']) {
      return new JsonResponse($result['data']);
    }

    if (isset($result['exception'])) {
      $this->getLogger('scolta')->error(
        'Summarize failed: @msg',
        ['@msg' => $result['exception']->getMessage(), 'exception' => $result['exception']]
      );
    }

    return new JsonResponse(['error' => $result['error']], $result['status']);
  }

}
