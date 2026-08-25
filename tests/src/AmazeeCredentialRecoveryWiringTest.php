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

    use Drupal\scolta_ui\Cache\DrupalCacheDriver;
    use PHPUnit\Framework\TestCase;
    use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
    use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
    use Tag1\Scolta\Cache\CacheDriverInterface;
    use Tag1\Scolta\Config\ScoltaConfig;
    use Tag1\Scolta\Health\HealthChecker;

    /**
     * Coverage for the Amazee.ai credential-recovery wiring.
     *
     * When the stored Amazee.ai credentials are no longer accepted, the next AI
     * call fails authentication. The adapter must degrade cleanly rather than
     * swallow it: record the failure so `/health` reports AI as degraded, set a
     * persistent marker so the admin UI prompts the operator to re-authenticate
     * (reconnect/upgrade), and leave the stored credentials in place — no new
     * connection is requested on this path. scolta-php 1.0.5 owns that
     * behaviour; these tests prove the Drupal-specific wiring that feeds it.
     *
     * The unit-test vendor lacks drupal/core, so ScoltaAiService itself cannot
     * be instantiated here. The wiring is proved two ways: behaviourally, that
     * the Drupal cache bridge satisfies KeyExpiryRecovery's marker contract and
     * keeps both `/health` and the re-authentication prompt truthful; and
     * structurally, that ScoltaAiService / scolta.module / AmazeeSettingsForm /
     * services.yml carry the wiring with the correct Amazee-path gate.
     * scolta-php's AiServiceAdapterTest proves the base call-path behaviour the
     * wiring feeds.
     */
    class AmazeeCredentialRecoveryWiringTest extends TestCase {

        private const STORED_CREDS = [
            'litellm_token' => 'sk-stored-token',
            'litellm_api_url' => 'https://llm.test.amazee.ai',
            'region' => 'test-region',
        ];

        // ---------------------------------------------------------------
        // Health truthfulness through the Drupal cache bridge
        // ---------------------------------------------------------------

        public function testHealthReportsAuthFailingWhenMarkerSet(): void {
            $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());

            // Record an auth failure exactly as a failing AI call would.
            $recovery = new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::STORED_CREDS), $driver);
            $recovery->recordAuthFailure();

            $result = $this->runHealthCheck($driver);

            $this->assertTrue($result['ai_configured'], 'Credentials are still present');
            $this->assertTrue($result['ai_auth_failing'], 'The recorded marker must surface');
            $this->assertFalse($result['ai_usable'], 'Known-bad credentials must not report usable');
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
        // The auth-failure contract through the Drupal cache bridge:
        // degrade + flag for re-authentication, never swallow, never mint.
        // ---------------------------------------------------------------

        public function testAuthFailureDegradesAndFlagsReauthLeavingCredentialsInPlace(): void {
            $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());
            $storage = new InMemoryRecoveryStorage(self::STORED_CREDS);
            // No client is supplied: the recovery path must never reach out to
            // obtain a connection, so there is nothing for it to call.
            $recovery = new KeyExpiryRecovery($storage, $driver);

            $handled = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

            $this->assertFalse($handled, 'The failure is not silently recovered — the caller degrades gracefully');
            $this->assertTrue($recovery->isAuthFailing(), 'Health must report AI as degraded');
            $this->assertTrue($recovery->isUpgradeNeeded(), 'The admin must be prompted to re-authenticate');
            $this->assertSame(
                self::STORED_CREDS,
                $recovery->credentials(),
                'The stored credentials are left in place — no new connection is requested'
            );
        }

        public function testReauthMarkerClearsThroughDrupalBridge(): void {
            $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());
            $recovery = new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::STORED_CREDS), $driver);

            $recovery->flagUpgradeNeeded();
            $this->assertTrue($recovery->isUpgradeNeeded(), 'Marker round-trips through the Drupal cache backend');

            // A successful reconnect clears it so the admin notice goes away.
            $recovery->clearUpgradeNeeded();
            $this->assertFalse($recovery->isUpgradeNeeded(), 'Clearing removes the re-authentication prompt');
        }

        public function testRecordAuthFailureVisibleThroughDrupalBridge(): void {
            $driver = new DrupalCacheDriver(new InMemoryRecoveryCacheBackend());
            $recovery = new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::STORED_CREDS), $driver);

            $this->assertFalse($recovery->isAuthFailing());

            $recovery->recordAuthFailure();

            $this->assertTrue($recovery->isAuthFailing(), 'Marker round-trips through the Drupal cache backend');
        }

        // ---------------------------------------------------------------
        // Structural: the wiring is present with the correct path gate
        // ---------------------------------------------------------------

        public function testServiceWiresRecoveryGatedOnAmazeePath(): void {
            $src = $this->source('modules/scolta_ui/src/Service/ScoltaAiService.php');

            $this->assertStringContainsString('use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;', $src);
            $this->assertStringContainsString('$this->setKeyExpiryRecovery(', $src);
            // The Amazee-path gate, now stated once rather than enumerated:
            // recovery is wired only when the shared resolution says Amazee is
            // the effective source, which is false for an explicit key and
            // false for the drupal_ai provider (Amazee is ineligible there).
            $this->assertStringContainsString('if (!$this->resolveApiKey()->isAmazee()) {', $src);
        }

        public function testServiceExposesReauthMarkerAccessors(): void {
            $src = $this->source('modules/scolta_ui/src/Service/ScoltaAiService.php');

            // The admin notice reads this; AmazeeSettingsForm clears it.
            $this->assertStringContainsString('public function isAmazeeReauthNeeded(): bool', $src);
            $this->assertStringContainsString('->isUpgradeNeeded()', $src);
            $this->assertStringContainsString('public function clearAmazeeReauthNeeded(): void', $src);
            $this->assertStringContainsString('->clearUpgradeNeeded()', $src);
        }

        public function testHookRendersReauthNoticeRoutingToAmazeeSettings(): void {
            // The notice is scolta_ui's: the credentials it is about belong to
            // the AI tier, and so does the settings flow it links to.
            $src = $this->source('modules/scolta_ui/scolta_ui.module');

            // hook_page_top surfaces the prompt by reading the service marker
            // and routes the operator to the Amazee.ai settings flow.
            $this->assertStringContainsString('isAmazeeReauthNeeded()', $src);
            $this->assertStringContainsString("Url::fromRoute('scolta.settings.amazee')", $src);
        }

        public function testSettingsFormClearsReauthMarkerOnReconnect(): void {
            $src = $this->source('modules/scolta_ui/src/Form/AmazeeSettingsForm.php');

            $this->assertStringContainsString('use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;', $src);
            // Completing the reconnect (either entry point) clears it.
            $this->assertSame(
                2,
                substr_count($src, '$this->keyRecovery->clearUpgradeNeeded();'),
                'Both reconnect paths must clear the re-authentication marker'
            );
        }

        public function testServicesYamlPassesCacheToAiService(): void {
            $yaml = PackageManifest::raw('services');
            $this->assertMatchesRegularExpression(
                '/scolta\.ai_service:.*?arguments:.*?@cache\.default/s',
                $yaml,
                'scolta.ai_service must receive @cache.default'
            );
        }

        public function testHealthControllerPassesCacheToHealthChecker(): void {
            $src = $this->source('modules/scolta_ui/src/Controller/HealthController.php');

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
