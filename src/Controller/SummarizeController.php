<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Tag1\Scolta\Http\AiEndpointHandler;

/**
 * Summarizes search results using the configured AI provider.
 *
 * POST /api/scolta/v1/summarize
 *   {"query": "product pricing", "context": "...excerpts..."}
 *   -> {"summary": "Our pricing plans include..."}
 */
class SummarizeController extends AiApiControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function invokeHandler(AiEndpointHandler $handler, array $body): array {
    return $handler->handleSummarize($body['query'] ?? '', $body['context'] ?? '');
  }

}
