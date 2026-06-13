<?php

declare(strict_types=1);

// Drupal's CacheBackendInterface is not shipped in scolta-drupal's unit-test
// vendor (only phpstan stubs for static analysis), so the bridge classes that
// type-hint it can't be instantiated without a shim. Define a minimal version
// matching ScoltaCacheBehaviorTest, guarded so the real interface wins when a
// full Drupal is present (the functional job).
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

    use Drupal\scolta\Cache\DrupalCacheDriver;
    use GuzzleHttp\Client;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Psr7\Response;
    use PHPUnit\Framework\TestCase;
    use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
    use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
    use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
    use Tag1\Scolta\Cache\CacheDriverInterface;
    use Tag1\Scolta\Config\ScoltaConfig;
    use Tag1\Scolta\Health\HealthChecker;

    /**
     * Coverage for the Amazee key-expiry recovery wiring.
     *
     * Regression (django demo, 2026-06-09): an Amazee trial key expired
     * server-side, every AI call returned 400 expired_key, and the adapter
     * neither recovered nor reported the truth — expand echoed the query while
     * /health still claimed `ai_configured: true`. ScoltaAiService now wires
     * KeyExpiryRecovery on the auto-provisioned path and HealthController hands
     * the same cache to HealthChecker.
     *
     * The unit-test vendor lacks drupal/core, so ScoltaAiService itself cannot
     * be instantiated here (same reason the other AI-service tests are
     * source-level). These tests prove the Drupal-specific wiring two ways:
     * behaviorally, that the Drupal cache bridge satisfies KeyExpiryRecovery's
     * marker contract and keeps HealthChecker truthful; and structurally, that
     * ScoltaAiService / HealthController / services.yml carry the wiring with
     * the correct Amazee-path gate. scolta-php's AiServiceAdapterTest proves the
     * base recover-and-retry loop the wiring feeds.
     */
    class KeyExpiryRecoveryWiringTest extends TestCase {

        private const FRESH_TRIAL_RESPONSE = '{"key": {"litellm_token": "sk-fresh-token", "litellm_api_url": "https://llm.test.amazee.ai", "region": "test-region"}}';
        private const MODEL_INFO_RESPONSE = '{"data": [{"model_name": "claude-sonnet-4-5"}, {"model_name": "claude-haiku-4-5"}]}';

        private const EXPIRED_CREDS = [
            'litellm_token' => 'sk-expired-token',
            'litellm_api_url' => 'https://llm.test.amazee.ai',
            'region' => 'test-region',
        ];

        // ---------------------------------------------------------------
        // Health truthfulness through the Drupal cache bridge
        // ---------------------------------------------------------------

        public function testHealthReportsAuthFailingWhenMarkerSet(): void {
            $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());

            // Record an auth failure exactly as a failing AI call would.
            $recovery = new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::EXPIRED_CREDS), $driver);
            $recovery->recordAuthFailure();

            $result = $this->runHealthCheck($driver);

            $this->assertTrue($result['ai_configured'], 'Credentials are still present');
            $this->assertTrue($result['ai_auth_failing'], 'The recorded marker must surface');
            $this->assertFalse($result['ai_usable'], 'Known-expired credentials must not report usable');
            $this->assertSame('degraded', $result['status']);
        }

        public function testHealthReportsUsableWhenNoMarker(): void {
            $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());

            $result = $this->runHealthCheck($driver);

            $this->assertTrue($result['ai_configured']);
            $this->assertFalse($result['ai_auth_failing']);
            $this->assertTrue($result['ai_usable'], 'A configured, non-failing key is usable');
        }

        public function testHealthCheckerWithoutCacheMirrorsConfigured(): void {
            // Null cache (recovery not wired) preserves the pre-recovery
            // behavior: ai_usable simply mirrors ai_configured.
            $result = $this->runHealthCheck(NULL);

            $this->assertTrue($result['ai_configured']);
            $this->assertFalse($result['ai_auth_failing']);
            $this->assertTrue($result['ai_usable']);
        }

        // ---------------------------------------------------------------
        // Recovery once-per-window through the Drupal cache bridge
        // ---------------------------------------------------------------

        public function testRecoveryReprovisionsOncePerWindowThroughDrupalBridge(): void {
            $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());
            $storage = new InMemoryRecoveryStorage(self::EXPIRED_CREDS);
            $recovery = new KeyExpiryRecovery(
                storage: $storage,
                cache: $driver,
                client: $this->makeAmazeeClient([
                    new Response(200, [], self::FRESH_TRIAL_RESPONSE),
                    new Response(200, [], self::MODEL_INFO_RESPONSE),
                ], $mock),
            );

            $first = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

            $this->assertTrue($first, 'An expired key triggers a re-provision');
            $this->assertSame('sk-fresh-token', $recovery->credentials()['litellm_token'], 'Fresh credentials stored');
            $this->assertFalse($recovery->isAuthFailing(), 'Successful recovery clears the marker via the Drupal cache');
            $this->assertSame(0, $mock->count(), 'Both provisioning calls (trial + models) ran');

            // A second failure inside the window must not hit the provisioning
            // API again — the MockHandler queue is empty, so any call throws.
            $second = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));
            $this->assertFalse($second, 'The window guard (read through the Drupal cache) blocks a second attempt');
        }

        public function testRecordAuthFailureVisibleThroughDrupalBridge(): void {
            $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());
            $recovery = new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::EXPIRED_CREDS), $driver);

            $this->assertFalse($recovery->isAuthFailing());

            $recovery->recordAuthFailure();

            $this->assertTrue($recovery->isAuthFailing(), 'Marker round-trips through the Drupal cache backend');
        }

        // ---------------------------------------------------------------
        // Structural: the wiring is present with the correct path gate
        // ---------------------------------------------------------------

        public function testServiceWiresRecoveryGatedOnAmazeePath(): void {
            $src = $this->source('src/Service/ScoltaAiService.php');

            $this->assertStringContainsString('use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;', $src);
            $this->assertStringContainsString('$this->setKeyExpiryRecovery(', $src);
            // The Amazee-path gate: an explicit key or the drupal_ai provider
            // must short-circuit before recovery is wired.
            $this->assertStringContainsString("\$this->getApiKey() !== ''", $src);
            $this->assertStringContainsString("aiProvider === 'drupal_ai'", $src);
        }

        public function testServiceOverridesRecoveredClientWithDrupalHttpClient(): void {
            $src = $this->source('src/Service/ScoltaAiService.php');

            $this->assertStringContainsString('function createRecoveredClient(', $src);
            $this->assertStringContainsString('new AiClient($config, $this->httpClient)', $src);
            $this->assertStringContainsString('?CacheBackendInterface $cache', $src);
        }

        public function testServicesYamlPassesCacheToAiService(): void {
            $yaml = $this->source('scolta.services.yml');
            $this->assertMatchesRegularExpression(
                '/scolta\.ai_service:.*?arguments:.*?@cache\.default/s',
                $yaml,
                'scolta.ai_service must receive @cache.default'
            );
        }

        public function testHealthControllerPassesCacheToHealthChecker(): void {
            $src = $this->source('src/Controller/HealthController.php');

            $this->assertStringContainsString("\$container->get('cache.default')", $src);
            $this->assertStringContainsString('new DrupalCacheDriver(', $src);
            $this->assertStringContainsString('cache: $cacheDriver', $src);
        }

        // ---------------------------------------------------------------
        // Helpers
        // ---------------------------------------------------------------

        private function source(string $relativePath): string {
            return file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        }

        /**
         * Run a HealthChecker for a configured Amazee install with the cache.
         */
        private function runHealthCheck(?CacheDriverInterface $cache): array {
            $config = ScoltaConfig::fromArray([
                'ai_provider' => 'openai',
                'ai_api_key' => 'sk-amazee-litellm-token',
            ]);
            $checker = new HealthChecker(
                config: $config,
                indexOutputDir: sys_get_temp_dir(),
                pagefindBinaryPath: NULL,
                projectDir: NULL,
                cache: $cache,
            );

            return $checker->check();
        }

        /**
         * Build an AmazeeClient backed by a MockHandler queue.
         */
        private function makeAmazeeClient(array $responses, ?MockHandler &$mock = NULL): AmazeeClient {
            $mock = new MockHandler($responses);
            return new AmazeeClient(
                'https://api.amazee.ai',
                new Client(['handler' => HandlerStack::create($mock)]),
            );
        }

    }

    /**
     * Minimal in-memory ConfigStorageInterface for the recovery store.
     */
    class InMemoryRecoveryStorage implements ConfigStorageInterface {

        public function __construct(private ?array $stored = NULL) {}

        public function store(string $litellmToken, string $litellmApiUrl, string $region): void {
            $this->stored = [
                'litellm_token' => $litellmToken,
                'litellm_api_url' => $litellmApiUrl,
                'region' => $region,
            ];
        }

        public function load(): ?array {
            return $this->stored;
        }

        public function clear(): void {
            $this->stored = NULL;
        }

    }

    /**
     * Minimal in-memory Drupal cache backend (TTL ignored — tests run in-window).
     *
     * get() returns a stdClass with a ->data property, or false on a miss, the
     * shape DrupalCacheDriver expects.
     */
    class InMemoryRecoveryCacheBackend implements \Drupal\Core\Cache\CacheBackendInterface {

        /** @var array<string, mixed> */
        private array $store = [];

        public function get($cid, $allow_invalid = FALSE): object|false {
            if (!array_key_exists($cid, $this->store)) {
                return FALSE;
            }
            $item = new \stdClass();
            $item->data = $this->store[$cid];
            return $item;
        }

        public function set($cid, $data, $expire = -1, array $tags = []): void {
            $this->store[$cid] = $data;
        }

        public function delete($cid): void {
            unset($this->store[$cid]);
        }

        public function deleteAll(): void {
            $this->store = [];
        }

        public function getMultiple(&$cids, $allow_invalid = FALSE): array {
            $result = [];
            foreach ($cids as $key => $cid) {
                $item = $this->get($cid);
                if ($item !== FALSE) {
                    $result[$cid] = $item;
                    unset($cids[$key]);
                }
            }
            return $result;
        }

        public function setMultiple(array $items): void {
            foreach ($items as $item) {
                $this->set($item['cid'], $item['data'], $item['expire'] ?? -1, $item['tags'] ?? []);
            }
        }

        public function deleteMultiple(array $cids): void {
            foreach ($cids as $cid) {
                $this->delete($cid);
            }
        }

        public function invalidate($cid): void {}

        public function invalidateMultiple(array $cids): void {}

        public function invalidateAll(): void {}

        public function garbageCollection(): void {}

        public function removeBin(): void {}

    }

}
