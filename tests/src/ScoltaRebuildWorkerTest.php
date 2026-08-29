<?php

declare(strict_types=1);

// The unit-test environment runs without drupal/core (CI "provides" it), so
// the queue-worker base class and the injected service interfaces are stubbed
// when absent — the same pattern ScoltaCacheBehaviorTest uses for the cache
// backend interface. Locally (and in the phpstan job) the real core classes
// exist and the stubs are skipped. Stubs carry the minimal method signatures
// the worker calls so PHPUnit can stub them in both environments.
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
        class SuspendQueueException extends \RuntimeException {
            public function __construct(
                string $message = '',
                int $code = 0,
                ?\Throwable $previous = null,
                public readonly float $delay = 0.0,
            ) {
                parent::__construct($message, $code, $previous);
            }
        }
    }
    if (!class_exists(QueueFactory::class)) {
        class QueueFactory {
            public function get($name, $reliable = false) {}
        }
    }
}

namespace Drupal\Core\Plugin {
    if (!interface_exists(ContainerFactoryPluginInterface::class)) {
        interface ContainerFactoryPluginInterface {}
    }
}

namespace Drupal\Core\Lock {
    if (!interface_exists(LockBackendInterface::class)) {
        interface LockBackendInterface {
            public function acquire($name, $timeout = 30.0);
            public function release($name);
        }
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
        interface EntityTypeManagerInterface {
            public function getStorage($entity_type_id);
        }
    }
}

namespace Drupal\Core\State {
    if (!interface_exists(StateInterface::class)) {
        interface StateInterface {
            public function get($key, $default = NULL);
            public function set($key, $value);
        }
    }
}

namespace Drupal\Core\Cache {
    if (!interface_exists(CacheTagsInvalidatorInterface::class)) {
        interface CacheTagsInvalidatorInterface {}
    }
}
// phpcs:enable

namespace Drupal\scolta\Tests {

    use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
    use Drupal\Core\Queue\SuspendQueueException;
    use Drupal\scolta\Plugin\QueueWorker\ScoltaRebuildWorker;
    use Drupal\scolta\Service\ScoltaContentGatherer;
    use PHPUnit\Framework\TestCase;
    use Psr\Log\NullLogger;

    /**
     * Tests ScoltaRebuildWorker's debounce behavior and DI structure.
     *
     * The worker must debounce on scolta.rebuild_requested_at + the backend's
     * auto_rebuild_delay so a burst of node saves produces one build, and it
     * must not delay the initial (install-time) build when no change has been
     * recorded. Pipeline parity with drush scolta:build is covered by the
     * functional tests (PipelineParityFunctionalTest and friends).
     */
    class ScoltaRebuildWorkerTest extends TestCase {

        private function createWorker(object $state): ScoltaRebuildWorker {
            return new ScoltaRebuildWorker(
                [],
                'scolta_rebuild',
                [],
                $this->createStub(\Drupal\Core\Lock\LockBackendInterface::class),
                $this->createStub(\Drupal\Core\Config\ConfigFactoryInterface::class),
                $this->createStub(\Drupal\Core\File\FileSystemInterface::class),
                $this->createStub(\Drupal\Core\StreamWrapper\StreamWrapperManagerInterface::class),
                $this->createStub(\Drupal\Core\Entity\EntityTypeManagerInterface::class),
                $state,
                $this->createStub(\Drupal\Core\Cache\CacheTagsInvalidatorInterface::class),
                new NullLogger(),
                $this->createStub(ScoltaContentGatherer::class),
                $this->createStub(\Drupal\Core\Queue\QueueFactory::class),
            );
        }

        // -------------------------------------------------------------------
        // Debounce behavior.
        // -------------------------------------------------------------------

        public function test_fresh_content_change_suspends_the_queue(): void {
            $state = $this->createStub(\Drupal\Core\State\StateInterface::class);
            // The last content change was 1 second ago — well inside any
            // configured delay (the floor is 60s, fallback 300s).
            $state->method('get')->willReturn(time() - 1);

            $worker = $this->createWorker($state);

            $this->expectException(SuspendQueueException::class);
            $this->expectExceptionMessageMatches('/Debouncing/');
            $worker->processItem(['type' => 'auto']);
        }

        public function test_debounce_skipped_when_no_change_recorded(): void {
            $state = $this->createStub(\Drupal\Core\State\StateInterface::class);
            // No recorded change (e.g. the install-time queue item): the
            // debounce must not delay the initial build. The build then
            // fails to acquire the (stub, falsy) lock — which proves the
            // code got PAST the debounce.
            $state->method('get')->willReturn(0);

            $worker = $this->createWorker($state);

            try {
                $worker->processItem(['type' => 'install']);
                $this->fail('Expected SuspendQueueException from the stub lock');
            }
            catch (SuspendQueueException $e) {
                $this->assertStringContainsString('lock', $e->getMessage(),
                    'With no recorded change the worker must reach the lock acquisition, not the debounce');
            }
        }

        // -------------------------------------------------------------------
        // Dependency injection structure.
        // -------------------------------------------------------------------

        public function test_worker_implements_container_factory_plugin_interface(): void {
            $ref = new \ReflectionClass(ScoltaRebuildWorker::class);
            $this->assertTrue(
                $ref->implementsInterface(ContainerFactoryPluginInterface::class),
                'ScoltaRebuildWorker must implement ContainerFactoryPluginInterface for injected dependencies'
            );
        }

    }

}
