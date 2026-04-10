<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\scolta\Prompt\EventDrivenEnricher;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiEndpointHandler;

/**
 * Handles follow-up questions about search results.
 *
 * POST /api/scolta/v1/followup
 *   {"messages": [...conversation history...]}
 *   -> {"response": "Based on the search results...", "remaining": 2}
 */
class FollowUpController extends ControllerBase {

  public function __construct(
    private readonly ScoltaAiService $aiService,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('event_dispatcher'),
    );
  }

  /**
   * Handle a follow-up question request.
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
      new NullCacheDriver(),
      0,
      0,
      $config->maxFollowUps,
      new EventDrivenEnricher($this->eventDispatcher),
      $config->aiLanguages,
    );

    $result = $handler->handleFollowUp($body['messages'] ?? []);

    if ($result['ok']) {
      return new JsonResponse($result['data']);
    }

    if (isset($result['exception'])) {
      $this->getLogger('scolta')->error(
        'Follow-up failed: @msg',
        ['@msg' => $result['exception']->getMessage(), 'exception' => $result['exception']]
      );
    }

    $response = ['error' => $result['error']];
    if (isset($result['limit'])) {
      $response['limit'] = $result['limit'];
    }
    return new JsonResponse($response, $result['status']);
  }

}
