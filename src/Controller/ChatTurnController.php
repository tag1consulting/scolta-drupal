<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tag1\Scolta\Chat\ChatOwner;
use Tag1\Scolta\Http\ChatEndpointHandler;
use Tag1\Scolta\Http\ServerSentEvent;

/**
 * POST /api/scolta/v1/chat/turn: answer one turn, streamed when asked.
 *
 * With Accept: text/event-stream the answer goes out as server-sent events,
 * each flushed as scolta-php yields it; otherwise as one JSON reply.
 *
 * @since 2.0.0
 * @stability experimental
 */
class ChatTurnController extends ChatControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function invokeChat(ChatEndpointHandler $handler, ChatOwner $owner, array $body, bool $chatHeader, Request $request): Response {
    if (!str_contains((string) $request->headers->get('Accept'), 'text/event-stream')) {
      return $this->jsonResult($handler->handleTurn($owner, $body, $chatHeader));
    }

    // Everything up to the first event (access, validation, the thread) runs
    // here, before any output, so a refusal is still an ordinary status code.
    $events = $handler->streamTurn($owner, $body, $chatHeader);
    [$event, $data] = $events->current();
    if ($event === 'error') {
      return $this->jsonResult(['ok' => FALSE, 'status' => $data['status'], 'error' => $data['message']]);
    }

    return new StreamedResponse(static function () use ($events): void {
      // A buffer or compression anywhere in the stack would hold every event
      // back until the answer is complete.
      if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
      }
      ini_set('zlib.output_compression', '0');
      while (ob_get_level() > 0) {
        ob_end_flush();
      }
      for (; $events->valid(); $events->next()) {
        [$event, $data] = $events->current();
        echo ServerSentEvent::format($event, $data);
        flush();
      }
    }, 200, [
      'Content-Type' => 'text/event-stream',
      'Cache-Control' => 'no-cache, no-store',
      'X-Accel-Buffering' => 'no',
    ]);
  }

}
