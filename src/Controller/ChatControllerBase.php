<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\State\StateInterface;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tag1\Scolta\Chat\ChatOwner;
use Tag1\Scolta\Chat\ThreadStoreInterface;
use Tag1\Scolta\Http\AiEndpointHandler;
use Tag1\Scolta\Http\ChatEndpointHandler;

/**
 * Base for the chat routes.
 *
 * Flood and parsing run as for the AI endpoints, then the owner, the header
 * flag and scolta-php's ChatEndpointHandler.
 *
 * Everything the chat decides (validation, prompts, context, fold, cache,
 * sources) is scolta-php's; this class resolves who is asking and what the
 * request carried. The owner is the user id, or for an anonymous visitor a
 * token in the scolta_chat cookie, renewed on every successful chat
 * response and scoped to the directory the chat routes are served under, so
 * no session is started.
 *
 * @since 2.0.0
 * @stability experimental
 */
abstract class ChatControllerBase extends AiApiControllerBase {

  final public function __construct(
    ScoltaAiService $aiService,
    EventDispatcherInterface $eventDispatcher,
    FloodInterface $flood,
    ?CacheBackendInterface $cache,
    ?StateInterface $state,
    protected readonly ThreadStoreInterface $threads,
  ) {
    parent::__construct($aiService, $eventDispatcher, $flood, $cache, $state);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('event_dispatcher'),
      $container->get('flood'),
      $container->get('cache.default'),
      $container->get('state'),
      $container->get('scolta.chat_thread_store'),
    );
  }

  /**
   * Answer the chat request with the handler.
   */
  abstract protected function invokeChat(ChatEndpointHandler $handler, ChatOwner $owner, array $body, bool $chatHeader, Request $request): Response;

  /**
   * {@inheritdoc}
   */
  protected function expectsBody(Request $request): bool {
    return $request->isMethod('POST');
  }

  /**
   * {@inheritdoc}
   */
  protected function respond(Request $request, array $body): Response {
    $config = $this->aiService->getConfig();
    $user = $this->currentUser();
    $owner = $user->isAuthenticated()
      ? ChatOwner::forUser((int) $user->id())
      : ChatOwner::fromCookie($request->cookies->get(ChatOwner::COOKIE_NAME));
    $handler = $this->createChatHandler(
      $this->aiService,
      $config,
      $this->threads,
      [$request->getHost()],
      $this->getLogger('scolta'),
    );

    $response = $this->invokeChat($handler, $owner, $body, $request->headers->get('X-Scolta-Chat') === '1', $request);

    // The routes sit side by side, so the directory of this one is the path
    // the browser sends the cookie back on, base path and language prefix
    // included.
    $cookie = $owner->cookie($request->isSecure(), $config->normalizedChat()['threadTtl'], dirname($request->getBaseUrl() . $request->getPathInfo()));
    if ($cookie !== NULL && $response->isSuccessful()) {
      $response->headers->setCookie(Cookie::create(
        $cookie['name'],
        $cookie['value'],
        time() + $cookie['maxAge'],
        $cookie['path'],
        NULL,
        $cookie['secure'],
        $cookie['httpOnly'],
        FALSE,
        strtolower($cookie['sameSite']),
      ));
    }

    return $response;
  }

  /**
   * Chat routes answer through invokeChat(); nothing calls this.
   */
  final protected function invokeHandler(AiEndpointHandler $handler, array $body): array {
    throw new \LogicException(static::class . ' answers through invokeChat().');
  }

}
