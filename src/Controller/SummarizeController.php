<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Summarizes search results using the configured AI provider.
 *
 * POST /api/scolta/v1/summarize
 *   {"query": "product pricing", "context": "...excerpts..."}
 *   → {"summary": "Our pricing plans include..."}
 */
class SummarizeController extends ControllerBase {

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
    $query = trim($body['query'] ?? '');
    $context = trim($body['context'] ?? '');

    if (empty($query) || strlen($query) > 500) {
      return new JsonResponse(['error' => 'Invalid query'], 400);
    }
    if (empty($context) || strlen($context) > 50000) {
      return new JsonResponse(['error' => 'Invalid context'], 400);
    }

    $userMessage = "Search query: {$query}\n\nSearch result excerpts:\n{$context}";

    try {
      $summary = $this->aiService->getClient()->message(
        $this->aiService->getSummarizePrompt(),
        $userMessage,
        512,
      );

      return new JsonResponse(['summary' => $summary]);
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
