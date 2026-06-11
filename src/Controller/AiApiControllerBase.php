<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\Cache\DrupalCacheDriver;
use Drupal\scolta\Prompt\EventDrivenEnricher;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiControllerTrait;
use Tag1\Scolta\Http\AiEndpointHandler;
use Tag1\Scolta\Prompt\PromptEnricherInterface;

/**
 * Shared request pipeline for the three AI API controllers.
 *
 * ExpandQueryController, SummarizeController, and FollowUpController were
 * ~95% identical (constructor, create(), JSON decode, flood/error shape,
 * cache resolution). This base owns the whole request flow; subclasses
 * implement invokeHandler() to call the right AiEndpointHandler method.
 *
 * Request flow: flood check (per-IP + global, fail closed 429) →
 * parseJsonBody() (shared scolta-php decode + 400 shape) → invokeHandler()
 * → shared success/error/limit response mapping.
 *
 * @since 1.0.4
 * @stability experimental
 */
abstract class AiApiControllerBase extends ControllerBase {

  use AiControllerTrait;

  /**
   * Flood event name for the per-IP threshold.
   */
  protected const FLOOD_IP_EVENT = 'scolta.ai_api_ip';

  /**
   * Flood event name for the site-wide threshold.
   */
  protected const FLOOD_GLOBAL_EVENT = 'scolta.ai_api_global';

  public function __construct(
    protected readonly ScoltaAiService $aiService,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly FloodInterface $flood,
    protected readonly ?CacheBackendInterface $cache = NULL,
    protected readonly ?StateInterface $state = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('event_dispatcher'),
      $container->get('flood'),
      $container->get('cache.default'),
      $container->get('state'),
    );
  }

  /**
   * Invoke the endpoint-specific AiEndpointHandler method.
   *
   * @param \Tag1\Scolta\Http\AiEndpointHandler $handler
   *   The fully-configured handler for this request.
   * @param array $body
   *   The decoded JSON request body.
   *
   * @return array
   *   The handler result array (ok/data/status/error[/limit] shape).
   */
  abstract protected function invokeHandler(AiEndpointHandler $handler, array $body): array;

  /**
   * Handle an AI API request.
   */
  public function handle(Request $request): JsonResponse {
    if (!$this->floodAllows($request)) {
      return new JsonResponse(['error' => 'Too many requests. Try again later.'], 429);
    }

    $parsed = $this->parseJsonBody((string) $request->getContent());
    if (!$parsed['ok']) {
      return new JsonResponse(['error' => $parsed['error']], $parsed['status']);
    }

    $config  = $this->aiService->getConfig();
    $handler = $this->createHandler($this->aiService, $config);
    $result  = $this->invokeHandler($handler, $parsed['data']);

    if ($result['ok']) {
      return new JsonResponse($result['data']);
    }

    if (isset($result['exception'])) {
      $this->getLogger('scolta')->error(
        '@controller failed: @msg',
        [
          '@controller' => static::class,
          '@msg' => $result['exception']->getMessage(),
          'exception' => $result['exception'],
        ]
      );
    }

    $response = ['error' => $result['error']];
    if (isset($result['limit'])) {
      $response['limit'] = $result['limit'];
    }
    return new JsonResponse($response, $result['status']);
  }

  /**
   * Register this request against the per-IP and global flood thresholds.
   *
   * These endpoints are anonymous-by-default, cost-bearing LLM calls, so
   * the check fails closed: if the flood backend itself errors, the request
   * is rejected rather than allowed to bypass rate limiting. A threshold
   * set to 0 disables that layer.
   *
   * @return bool
   *   TRUE when the request is within both thresholds.
   */
  protected function floodAllows(Request $request): bool {
    $config = $this->config('scolta.settings');
    $ipLimit = (int) ($config->get('flood.ai_ip_limit') ?? 60);
    $ipWindow = (int) ($config->get('flood.ai_ip_window') ?? 60);
    $globalLimit = (int) ($config->get('flood.ai_global_limit') ?? 1000);
    $globalWindow = (int) ($config->get('flood.ai_global_window') ?? 60);

    try {
      if ($ipLimit > 0) {
        $identifier = (string) $request->getClientIp();
        if (!$this->flood->isAllowed(self::FLOOD_IP_EVENT, $ipLimit, $ipWindow, $identifier)) {
          return FALSE;
        }
        $this->flood->register(self::FLOOD_IP_EVENT, $ipWindow, $identifier);
      }
      if ($globalLimit > 0) {
        if (!$this->flood->isAllowed(self::FLOOD_GLOBAL_EVENT, $globalLimit, $globalWindow, 'global')) {
          return FALSE;
        }
        $this->flood->register(self::FLOOD_GLOBAL_EVENT, $globalWindow, 'global');
      }
    }
    catch (\Throwable $e) {
      $this->getLogger('scolta')->error('Flood check failed (failing closed): @msg', ['@msg' => $e->getMessage()]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveCache(int $cacheTtl): CacheDriverInterface {
    return ($cacheTtl > 0 && $this->cache !== NULL)
      ? new DrupalCacheDriver($this->cache)
      : new NullCacheDriver();
  }

  /**
   * {@inheritdoc}
   */
  protected function getCacheGeneration(): int {
    return $this->state !== NULL
      ? (int) $this->state->get('scolta.generation', 0)
      : 0;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveEnricher(): PromptEnricherInterface {
    return new EventDrivenEnricher($this->eventDispatcher);
  }

}
