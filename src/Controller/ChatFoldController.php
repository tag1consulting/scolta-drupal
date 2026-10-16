<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tag1\Scolta\Chat\ChatOwner;
use Tag1\Scolta\Http\ChatEndpointHandler;

/**
 * POST /api/scolta/v1/chat/fold: summarize what left the window.
 *
 * @since 2.0.0
 * @stability experimental
 */
class ChatFoldController extends ChatControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function invokeChat(ChatEndpointHandler $handler, ChatOwner $owner, array $body, bool $chatHeader, Request $request): Response {
    return $this->jsonResult($handler->handleFold($owner, $body, $chatHeader));
  }

}
