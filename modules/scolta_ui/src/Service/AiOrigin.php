<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;

/**
 * Where this site's AI requests are answered.
 *
 * The second of the two pointer settings, and the smaller one. Unlike the
 * index, the AI tier is a set of three POST endpoints, so pointing elsewhere
 * is just a matter of which base URL the browser posts to; nothing about the
 * request or the response changes.
 *
 * A remote AI origin does not make this site's own endpoints go away — they
 * stay routed and permission-checked as they were, so a site can point its
 * visitors at another backend and still be one itself.
 */
class AiOrigin {

  /**
   * The value meaning "this site answers its own AI".
   *
   * The same sentinel the index origin uses, deliberately: an operator reads
   * the two settings side by side and they must not disagree about what
   * "local" is written as.
   */
  public const LOCAL = IndexOrigin::LOCAL;

  /**
   * The route serving each AI endpoint locally, keyed as the browser knows it.
   */
  private const ENDPOINT_ROUTES = [
    'expand' => 'scolta.expand',
    'summarize' => 'scolta.summarize',
    'followup' => 'scolta.followup',
  ];

  /**
   * The same endpoints as paths, for composing against a remote origin.
   *
   * Spelled out rather than taken from the router. A local URL carries this
   * site's own prefixes — a subdirectory install, a language prefix — and
   * appending one of those to another site's origin would ask it for a path
   * it does not serve. /api/scolta/v1/ is the documented contract between a
   * frontend and a backend, so the remote is asked for exactly that.
   */
  private const ENDPOINT_PATHS = [
    'expand' => '/api/scolta/v1/expand-query',
    'summarize' => '/api/scolta/v1/summarize',
    'followup' => '/api/scolta/v1/followup',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The configured origin: <local>, or an absolute URL.
   */
  public function origin(): string {
    $value = trim((string) ($this->configFactory->get('scolta_ui.settings')->get('ai_origin') ?? ''));
    return $value === '' ? self::LOCAL : rtrim($value, '/');
  }

  /**
   * Whether AI requests are answered by another site.
   */
  public function isRemote(): bool {
    return $this->origin() !== self::LOCAL;
  }

  /**
   * The remote origin URL, or NULL when AI is answered locally.
   */
  public function remoteBase(): ?string {
    return $this->isRemote() ? $this->origin() : NULL;
  }

  /**
   * The three AI endpoint URLs the browser should post to.
   *
   * Local endpoints come from the router, so a site with a path prefix or a
   * language prefix gets the URL it actually serves. A remote origin is
   * composed from the documented paths instead — see ENDPOINT_PATHS.
   *
   * @return array<string, string>
   *   Endpoint URLs keyed by 'expand', 'summarize' and 'followup'.
   */
  public function endpoints(): array {
    $base = $this->remoteBase();

    if ($base !== NULL) {
      return array_map(static fn(string $path): string => $base . $path, self::ENDPOINT_PATHS);
    }

    $endpoints = [];
    foreach (self::ENDPOINT_ROUTES as $name => $route) {
      $endpoints[$name] = Url::fromRoute($route)->toString();
    }

    return $endpoints;
  }

}
