<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tag1\Scolta\Chat\ChatOwner;
use Tag1\Scolta\Http\ChatEndpointHandler;

/**
 * GET /api/scolta/v1/chat/thread restores a thread; DELETE starts a new one.
 *
 * @since 2.0.0
 * @stability experimental
 */
class ChatThreadController extends ChatControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function invokeChat(ChatEndpointHandler $handler, ChatOwner $owner, array $body, bool $chatHeader, Request $request): Response {
    if ($request->isMethod('DELETE')) {
      return $this->jsonResult($handler->handleReset($owner, $chatHeader));
    }
    $threadId = $request->query->get('thread_id');

    return $this->jsonResult($handler->handleThread($owner, is_string($threadId) ? $threadId : NULL, $chatHeader));
  }

}
