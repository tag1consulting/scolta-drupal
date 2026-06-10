<?php

declare(strict_types=1);

// The unit-test environment runs without drupal/core (CI "provides" it), so
// the queue-worker base class and the injected service interfaces are stubbed
// when absent — the same pattern ScoltaCacheBehaviorTest uses for the cache
// backend interface. Locally (and in the phpstan job) the real core classes
// exist and the stubs are skipped.
// phpcs:disable
namespace Drupal\Core\Queue {
    if (!class_exists(QueueWorkerBase::class)) {
        abstract class QueueWorkerBase {
            public function __construct(
                protected array $configuration,
                protected $pluginId,
                protected $pluginDefinition,
            ) {}
        }
    }
    if (!class_exists(SuspendQueueException::class)) {
        class SuspendQueueException extends \RuntimeException {}
    }
}

namespace Drupal\Core\Plugin {
    if (!interface_exists(ContainerFactoryPluginInterface::class)) {
        interface ContainerFactoryPluginInterface {}
    }
}

namespace Drupal\Core\Lock {
    if (!interface_exists(LockBackendInterface::class)) {
        interface LockBackendInterface {}
    }
}

namespace Drupal\Core\Config {
    if (!interface_exists(ConfigFactoryInterface::class)) {
        interface ConfigFactoryInterface {}
    }
}

namespace Drupal\Core\File {
    if (!interface_exists(FileSystemInterface::class)) {
        interface FileSystemInterface {}
    }
}

namespace Drupal\Core\StreamWrapper {
    if (!interface_exists(StreamWrapperManagerInterface::class)) {
        interface StreamWrapperManagerInterface {}
    }
}

namespace Drupal\Core\Entity {
    if (!interface_exists(EntityTypeManagerInterface::class)) {
        interface EntityTypeManagerInterface {}
    }
}

namespace Drupal\Core\State {
    if (!interface_exists(StateInterface::class)) {
        interface StateInterface {}
    }
}

namespace Drupal\Core\Cache {
    if (!interface_exists(CacheTagsInvalidatorInterface::class)) {
        interface CacheTagsInvalidatorInterface {}
    }
}
// phpcs:enable

namespace Drupal\scolta\Tests {

    use Drupal\scolta\Plugin\QueueWorker\ScoltaRebuildWorker;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\AbstractLogger;

    /**
     * Tests ScoltaRebuildWorker dependency injection and the fingerprint
     * failure path.
     *
     * The error path previously fataled: QueueWorkerBase has no getLogger(),
     * and the "Call to an undefined method" was baselined instead of fixed,
     * so a failed fingerprint write crashed cron. writeFingerprint() must log
     * through the injected logger and never throw.
     */
    class ScoltaRebuildWorkerTest extends TestCase {

        private function createWorker(SpyLogger $logger): ScoltaRebuildWorker {
            return new ScoltaRebuildWorker(
                [],
                'scolta_rebuild',
                [],
                $this->createStub(\Drupal\Core\Lock\LockBackendInterface::class),
                $this->createStub(\Drupal\Core\Config\ConfigFactoryInterface::class),
                $this->createStub(\Drupal\Core\File\FileSystemInterface::class),
                $this->createStub(\Drupal\Core\StreamWrapper\StreamWrapperManagerInterface::class),
                $this->createStub(\Drupal\Core\Entity\EntityTypeManagerInterface::class),
                $this->createStub(\Drupal\Core\State\StateInterface::class),
                $this->createStub(\Drupal\Core\Cache\CacheTagsInvalidatorInterface::class),
                $logger,
            );
        }

        private function callWriteFingerprint(ScoltaRebuildWorker $worker, string $path, string $fp): void {
            $method = new \ReflectionMethod($worker, 'writeFingerprint');
            $method->invoke($worker, $path, $fp);
        }

        // -------------------------------------------------------------------
        // Fingerprint write failure path (the previously-fatal error path).
        // -------------------------------------------------------------------

        public function test_fingerprint_write_failure_logs_error_without_fatal(): void {
            $logger = new SpyLogger();
            $worker = $this->createWorker($logger);

            $this->callWriteFingerprint(
                $worker,
                '/nonexistent-scolta-test-dir-' . uniqid() . '/.scolta-state',
                'fingerprint-value'
            );

            $this->assertCount(1, $logger->records, 'A failed fingerprint write must log exactly one error.');
            $this->assertSame('error', $logger->records[0]['level']);
            $this->assertStringContainsString('fingerprint', $logger->records[0]['message']);
        }

        public function test_fingerprint_write_success_logs_nothing(): void {
            $logger = new SpyLogger();
            $worker = $this->createWorker($logger);

            $dir = sys_get_temp_dir() . '/scolta-worker-test-' . uniqid();
            mkdir($dir);
            $path = $dir . '/.scolta-state';

            try {
                $this->callWriteFingerprint($worker, $path, 'fingerprint-value');

                $this->assertSame('fingerprint-value', file_get_contents($path));
                $this->assertSame([], $logger->records, 'A successful write must not log.');
            } finally {
                @unlink($path);
                @rmdir($dir);
            }
        }

        // -------------------------------------------------------------------
        // Dependency injection structure.
        // -------------------------------------------------------------------

        public function test_worker_implements_container_factory_plugin_interface(): void {
            $contents = $this->workerSource();
            $this->assertStringContainsString(
                'implements ContainerFactoryPluginInterface',
                $contents,
                'ScoltaRebuildWorker must implement ContainerFactoryPluginInterface for injected dependencies'
            );
        }

        public function test_worker_has_no_static_drupal_calls(): void {
            $contents = $this->workerSource();
            $this->assertStringNotContainsString(
                '\Drupal::',
                $contents,
                'ScoltaRebuildWorker must use injected services, not \Drupal:: statics'
            );
        }

        public function test_worker_does_not_call_undefined_get_logger(): void {
            $contents = $this->workerSource();
            $this->assertStringNotContainsString(
                '$this->getLogger(',
                $contents,
                'QueueWorkerBase has no getLogger() — the worker must use its injected logger'
            );
        }

        public function test_baseline_no_longer_carries_the_get_logger_fatal(): void {
            $baseline = file_get_contents(dirname(__DIR__, 2) . '/phpstan-baseline.neon');
            $this->assertStringNotContainsString(
                'ScoltaRebuildWorker\:\:getLogger',
                $baseline,
                'The baselined runtime fatal must be fixed, not ignored'
            );
        }

        private function workerSource(): string {
            return file_get_contents(
                dirname(__DIR__, 2) . '/src/Plugin/QueueWorker/ScoltaRebuildWorker.php'
            );
        }

    }

    /**
     * Collects log records for assertions.
     */
    class SpyLogger extends AbstractLogger {

        /** @var array<int, array{level: string, message: string}> */
        public array $records = [];

        public function log($level, string|\Stringable $message, array $context = []): void {
            $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
        }

    }

}
