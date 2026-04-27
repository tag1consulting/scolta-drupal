<?php

declare(strict_types=1);

// Drupal's CacheBackendInterface is not shipped in scolta-drupal's vendor
// (only in phpstan stubs for static analysis). Define a minimal version so
// DrupalCacheDriver can be instantiated in plain PHPUnit tests.
// phpcs:disable
namespace Drupal\Core\Cache {
    if (!interface_exists(\Drupal\Core\Cache\CacheBackendInterface::class)) {
        interface CacheBackendInterface {
            public function get(string $cid, bool $allow_invalid = false);
            public function set(string $cid, mixed $data, int $expire = -1, array $tags = []): void;
            public function delete(string $cid): void;
            public function deleteAll(): void;
            public function invalidate(string $cid): void;
            public function invalidateAll(): void;
            public function garbageCollection(): void;
        }
    }
}
// phpcs:enable

namespace Drupal\scolta\Tests {

    use Drupal\scolta\Cache\DrupalCacheDriver;
    use PHPUnit\Framework\TestCase;
    use Tag1\Scolta\Http\AiEndpointHandler;

    /**
     * Tests that DrupalCacheDriver correctly mediates between
     * AiEndpointHandler and Drupal's cache backend.
     */
    class ScoltaCacheBehaviorTest extends TestCase {

        // -------------------------------------------------------------------
        // Driver contract — get/set/miss
        // -------------------------------------------------------------------

        public function test_get_returns_null_on_miss(): void {
            $driver = new DrupalCacheDriver( new InMemoryDrupalCacheBackend() );
            $this->assertNull( $driver->get( 'nonexistent_key' ) );
        }

        public function test_set_then_get_returns_value(): void {
            $driver = new DrupalCacheDriver( new InMemoryDrupalCacheBackend() );
            $driver->set( 'test_key', 'hello world', 3600 );
            $this->assertEquals( 'hello world', $driver->get( 'test_key' ) );
        }

        public function test_different_keys_are_independent(): void {
            $driver = new DrupalCacheDriver( new InMemoryDrupalCacheBackend() );
            $driver->set( 'key_a', 'value_a', 3600 );
            $driver->set( 'key_b', 'value_b', 3600 );
            $this->assertEquals( 'value_a', $driver->get( 'key_a' ) );
            $this->assertEquals( 'value_b', $driver->get( 'key_b' ) );
            $this->assertNull( $driver->get( 'key_c' ) );
        }

        public function test_set_stores_array_value(): void {
            $driver = new DrupalCacheDriver( new InMemoryDrupalCacheBackend() );
            $driver->set( 'arr_key', [ 'term1', 'term2', 'term3' ], 3600 );
            $this->assertEquals( [ 'term1', 'term2', 'term3' ], $driver->get( 'arr_key' ) );
        }

        // -------------------------------------------------------------------
        // Handler integration — caching hits and misses
        // -------------------------------------------------------------------

        public function test_second_expand_call_uses_cache(): void {
            $ai     = new DrupalTestMockAiService( '["term1","term2","term3"]' );
            $driver = new DrupalCacheDriver( new InMemoryDrupalCacheBackend() );
            $handler = new AiEndpointHandler(
                aiService: $ai,
                cache: $driver,
                generation: 1,
                cacheTtl: 3600,
                maxFollowUps: 3,
            );

            $handler->handleExpandQuery( 'cache test query' );
            $handler->handleExpandQuery( 'cache test query' );

            $this->assertEquals( 1, $ai->callCount, 'AI service should be called only once — second call serves from cache' );
        }

        public function test_cache_ttl_zero_calls_ai_every_time(): void {
            $ai     = new DrupalTestMockAiService( '["term1","term2","term3"]' );
            $driver = new DrupalCacheDriver( new InMemoryDrupalCacheBackend() );
            $handler = new AiEndpointHandler(
                aiService: $ai,
                cache: $driver,
                generation: 1,
                cacheTtl: 0,
                maxFollowUps: 3,
            );

            $handler->handleExpandQuery( 'no cache query' );
            $handler->handleExpandQuery( 'no cache query' );

            $this->assertEquals( 2, $ai->callCount, 'AI service should be called every time when cacheTtl=0' );
        }

        public function test_second_summarize_call_uses_cache(): void {
            $ai     = new DrupalTestMockAiService( 'A helpful summary.' );
            $driver = new DrupalCacheDriver( new InMemoryDrupalCacheBackend() );
            $handler = new AiEndpointHandler(
                aiService: $ai,
                cache: $driver,
                generation: 1,
                cacheTtl: 3600,
                maxFollowUps: 3,
            );

            $handler->handleSummarize( 'search query', 'some context text' );
            $handler->handleSummarize( 'search query', 'some context text' );

            $this->assertEquals( 1, $ai->callCount, 'AI service should be called only once — second summarize call serves from cache' );
        }

    }

    // -----------------------------------------------------------------------
    // Test doubles
    // -----------------------------------------------------------------------

    /**
     * Minimal in-memory Drupal cache backend for testing.
     *
     * Returns stdClass objects matching the shape DrupalCacheDriver expects:
     * $cached->data contains the stored value; false means a cache miss.
     */
    class InMemoryDrupalCacheBackend implements \Drupal\Core\Cache\CacheBackendInterface {

        /** @var array<string, mixed> */
        private array $store = [];

        public function get( string $cid, bool $allow_invalid = false ): object|false {
            if ( ! array_key_exists( $cid, $this->store ) ) {
                return false;
            }
            $item = new \stdClass();
            $item->data = $this->store[$cid];
            return $item;
        }

        public function set( string $cid, mixed $data, int $expire = -1, array $tags = [] ): void {
            $this->store[$cid] = $data;
        }

        public function delete( string $cid ): void {
            unset( $this->store[$cid] );
        }

        public function deleteAll(): void {
            $this->store = [];
        }

        public function invalidate( string $cid ): void {}

        public function invalidateAll(): void {}

        public function garbageCollection(): void {}

    }

    class DrupalTestMockAiService {

        public int $callCount = 0;

        public function __construct(
            private readonly string $response = '',
        ) {}

        public function getExpandPrompt(): string {
            return 'Expand the following search query.';
        }

        public function getSummarizePrompt(): string {
            return 'Summarize the following search results.';
        }

        public function getFollowUpPrompt(): string {
            return 'Continue the conversation.';
        }

        public function message( string $systemPrompt, string $userMessage, int $maxTokens ): string {
            $this->callCount++;
            return $this->response;
        }

        public function conversation( string $systemPrompt, array $messages, int $maxTokens ): string {
            $this->callCount++;
            return $this->response;
        }

    }

}
