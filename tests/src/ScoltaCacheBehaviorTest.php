<?php

declare(strict_types=1);

// Drupal's CacheBackendInterface is not shipped in scolta-drupal's vendor
// (only in phpstan stubs for static analysis). Define a minimal version so
// DrupalCacheDriver can be instantiated in plain PHPUnit tests.
// phpcs:disable
namespace Drupal\Core\Cache {
    if (!interface_exists(\Drupal\Core\Cache\CacheBackendInterface::class)) {
        interface CacheBackendInterface {
            public function get($cid, $allow_invalid = false);
            public function set($cid, $data, $expire = -1, array $tags = []);
            public function delete($cid);
            public function deleteAll();
            public function invalidate($cid);
            public function invalidateAll();
            public function garbageCollection();
        }
    }
}
// phpcs:enable

namespace Drupal\scolta\Tests {

    use Drupal\scolta_ui\Cache\DrupalCacheDriver;
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

        // -------------------------------------------------------------------
        // Generation bump — the invalidation mechanism scolta:clear-cache and
        // the rebuild paths rely on. Entries cached at generation N must not
        // be served at generation N+1, and the shared backend keeps every
        // foreign entry: nothing may call deleteAll() on the bin.
        // -------------------------------------------------------------------

        public function test_generation_bump_invalidates_cached_ai_entries(): void {
            $backend = new InMemoryDrupalCacheBackend();
            $ai      = new DrupalTestMockAiService( '["term1","term2"]' );

            $handlerGen1 = new AiEndpointHandler(
                aiService: $ai,
                cache: new DrupalCacheDriver( $backend ),
                generation: 1,
                cacheTtl: 3600,
                maxFollowUps: 3,
            );
            $handlerGen1->handleExpandQuery( 'generation test' );
            $this->assertEquals( 1, $ai->callCount );

            // Same backend, bumped generation — the old entry must be a miss.
            $handlerGen2 = new AiEndpointHandler(
                aiService: $ai,
                cache: new DrupalCacheDriver( $backend ),
                generation: 2,
                cacheTtl: 3600,
                maxFollowUps: 3,
            );
            $handlerGen2->handleExpandQuery( 'generation test' );
            $this->assertEquals( 2, $ai->callCount, 'A generation bump must invalidate previously cached AI responses' );
        }

        public function test_generation_bump_leaves_foreign_cache_entries_intact(): void {
            $backend = new InMemoryDrupalCacheBackend();
            $backend->set( 'views:some_other_module_entry', 'precious' );

            $ai = new DrupalTestMockAiService( '["term1"]' );
            $handler = new AiEndpointHandler(
                aiService: $ai,
                cache: new DrupalCacheDriver( $backend ),
                generation: 2,
                cacheTtl: 3600,
                maxFollowUps: 3,
            );
            $handler->handleExpandQuery( 'generation test' );

            // The targeted invalidation strategy (generation bump + fixed-key
            // prompt deletes) never touches entries owned by other modules.
            $cached = $backend->get( 'views:some_other_module_entry' );
            $this->assertNotFalse( $cached );
            $this->assertEquals( 'precious', $cached->data );
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

        public function get( $cid, $allow_invalid = false ): object|false {
            if ( ! array_key_exists( $cid, $this->store ) ) {
                return false;
            }
            $item = new \stdClass();
            $item->data = $this->store[$cid];
            return $item;
        }

        public function set( $cid, $data, $expire = -1, array $tags = [] ): void {
            $this->store[$cid] = $data;
        }

        public function delete( $cid ): void {
            unset( $this->store[$cid] );
        }

        public function deleteAll(): void {
            $this->store = [];
        }

        public function invalidate( $cid ): void {}

        public function getMultiple( &$cids, $allow_invalid = false ): array {
            $result = [];
            foreach ( $cids as $key => $cid ) {
                $item = $this->get( $cid );
                if ( $item !== false ) {
                    $result[$cid] = $item;
                    unset( $cids[$key] );
                }
            }
            return $result;
        }

        public function setMultiple( array $items ): void {
            foreach ( $items as $item ) {
                $this->set( $item['cid'], $item['data'], $item['expire'] ?? -1, $item['tags'] ?? [] );
            }
        }

        public function deleteMultiple( array $cids ): void {
            foreach ( $cids as $cid ) {
                $this->delete( $cid );
            }
        }

        public function invalidateMultiple( array $cids ): void {}

        public function invalidateAll(): void {}

        public function garbageCollection(): void {}

        public function removeBin(): void {}

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

        public function messageForOperation( string $operation, string $systemPrompt, string $userMessage, int $maxTokens ): string {
            return $this->message( $systemPrompt, $userMessage, $maxTokens );
        }

        public function conversation( string $systemPrompt, array $messages, int $maxTokens ): string {
            $this->callCount++;
            return $this->response;
        }

    }

}
