<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tag1\Scolta\Chat\ChatOwner;
use Tag1\Scolta\Http\ChatEndpointHandler;

/**
 * POST /api/scolta/v1/chat/plan: rewrite and expand a follow up.
 *
 * @since 2.0.0
 * @stability experimental
 */
class ChatPlanController extends ChatControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function invokeChat(ChatEndpointHandler $handler, ChatOwner $owner, array $body, bool $chatHeader, Request $request): Response {
    return $this->jsonResult($handler->handlePlan($owner, $body, $chatHeader));
  }

}
