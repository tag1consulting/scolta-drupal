<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Controller;

use Tag1\Scolta\Http\AiEndpointHandler;

/**
 * Expands a search query into related terms using the configured AI provider.
 *
 * POST /api/scolta/v1/expand-query
 *   {"query": "product pricing"}
 *   -> ["cost", "pricing plans", "rates", "subscription tiers"]
 */
class ExpandQueryController extends AiApiControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function invokeHandler(AiEndpointHandler $handler, array $body): array {
    return $handler->handleExpandQuery($body['query'] ?? '');
  }

}
