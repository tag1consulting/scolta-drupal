<?php

declare(strict_types=1);

namespace Drupal\scolta_amazee_test;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle client that answers every Amazee.ai call from canned responses.
 */
final class FakeAmazeeHttpClient {

  public const TOKEN = 'sk-fake-token';
  public const GATEWAY_URL = 'https://llm.fake.amazee.ai';

  /**
   * Builds the client; the service factory.
   */
  public static function create(): Client {
    return new Client(['handler' => HandlerStack::create([self::class, 'handle'])]);
  }

  /**
   * Answers one request by its path.
   */
  public static function handle(RequestInterface $request): PromiseInterface {
    $body = match ($request->getUri()->getPath()) {
      '/auth/generate-trial-access' => [
        'key' => [
          'litellm_token' => self::TOKEN,
          'litellm_api_url' => self::GATEWAY_URL,
          'region' => 'fake-region',
        ],
      ],
      '/model/info' => [
        'data' => [
          ['model_name' => 'claude-sonnet-4-5'],
          ['model_name' => 'claude-haiku-4-5'],
        ],
      ],
      '/auth/validate-email' => [],
      '/auth/sign-in' => ['token' => ['access_token' => 'fake-session']],
      '/regions' => [['id' => 'fake-region', 'name' => 'Fake region']],
      '/private-ai-keys' => [
        'litellm_token' => self::TOKEN,
        'litellm_api_url' => self::GATEWAY_URL,
        'region' => 'fake-region',
      ],
      default => NULL,
    };

    return Create::promiseFor($body === NULL
      ? new Response(404, [], '{"detail":"Not found"}')
      : new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
  }

}
