<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles follow-up questions about search results.
 *
 * POST /api/scolta/v1/followup
 *   {"messages": [...conversation history...]}
 *   → {"response": "Based on the search results...", "remaining": 2}
 */
class FollowUpController extends ControllerBase {

  public function __construct(
    private readonly ScoltaAiService $aiService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
    );
  }

  public function handle(Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    $messages = $body['messages'] ?? [];

    if (empty($messages) || !is_array($messages)) {
      return new JsonResponse(['error' => 'Messages required'], 400);
    }

    $config = $this->aiService->getConfig();
    $maxFollowUps = $config->maxFollowUps;

    // Enforce follow-up limit server-side.
    $followUpsSoFar = (int) ((count($messages) - 2) / 2);
    if ($followUpsSoFar >= $maxFollowUps) {
      return new JsonResponse([
        'error' => 'Follow-up limit reached',
        'limit' => $maxFollowUps,
      ], 429);
    }

    // Validate each message has role and content.
    foreach ($messages as $msg) {
      if (empty($msg['role']) || empty($msg['content'])) {
        return new JsonResponse(['error' => 'Invalid message format'], 400);
      }
      if (!in_array($msg['role'], ['user', 'assistant'], TRUE)) {
        return new JsonResponse(['error' => 'Invalid role'], 400);
      }
    }

    // Last message must be from the user.
    if (end($messages)['role'] !== 'user') {
      return new JsonResponse(['error' => 'Last message must be from user'], 400);
    }

    try {
      $response = $this->aiService->getClient()->conversation(
        $this->aiService->getFollowUpPrompt(),
        $messages,
        512,
      );

      $remaining = $maxFollowUps - $followUpsSoFar - 1;
      return new JsonResponse([
        'response' => $response,
        'remaining' => max(0, $remaining),
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('scolta')->error(
        'Follow-up failed: @msg',
        ['@msg' => $e->getMessage()]
      );
      return new JsonResponse(['error' => 'Follow-up unavailable'], 503);
    }
  }

}
