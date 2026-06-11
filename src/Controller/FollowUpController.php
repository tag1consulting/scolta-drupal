<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiEndpointHandler;

/**
 * Handles follow-up questions about search results.
 *
 * POST /api/scolta/v1/followup
 *   {"messages": [...conversation history...]}
 *   -> {"response": "Based on the search results...", "remaining": 2}
 */
class FollowUpController extends AiApiControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function invokeHandler(AiEndpointHandler $handler, array $body): array {
    return $handler->handleFollowUp($body['messages'] ?? []);
  }

  /**
   * {@inheritdoc}
   *
   * Follow-up conversations are stateful and never cached.
   */
  protected function resolveCache(int $cacheTtl): CacheDriverInterface {
    return new NullCacheDriver();
  }

  /**
   * {@inheritdoc}
   */
  protected function getCacheGeneration(): int {
    return 0;
  }

}
