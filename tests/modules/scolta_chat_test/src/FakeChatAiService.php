<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_test;

use Drupal\scolta\Service\ScoltaAiService;

/**
 * The real AI service with every model call answered from a script.
 *
 * The answer cites the first page it was sent, so a test sees a real source
 * list come back.
 */
class FakeChatAiService extends ScoltaAiService {

  /**
   * {@inheritdoc}
   */
  public function messageForOperation(string $operation, string $systemPrompt, string $userMessage, int $maxTokens = 512): string {
    return '{"query": "planned query", "needs_search": true, "terms": ["alpha"]}';
  }

  /**
   * {@inheritdoc}
   */
  public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string {
    return 'Folded summary.';
  }

  /**
   * {@inheritdoc}
   */
  public function conversationStream(string $systemPrompt, array $messages, int $maxTokens = 1024, ?string $model = NULL, ?float $temperature = NULL, bool $cacheSystem = FALSE): \Generator {
    $last = (string) (end($messages)['content'] ?? '');
    if (preg_match('#<page n="1" title="[^"]*" url="([^"]+)">#', $last, $m)) {
      yield 'The page says so ';
      yield "[[1]]({$m[1]}).\nSecond line.";
      return;
    }
    yield 'Hello. Ask me about this site.';
  }

}
