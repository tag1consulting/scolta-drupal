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
        interface ConfigFactoryInterface {
            public function get($name);
            public function getEditable($name);
        }
    }
    if (!class_exists(ImmutableConfig::class)) {
        class ImmutableConfig {
            public function get($key) {}
        }
    }
}

namespace Drupal\Core\File {
    if (!interface_exists(FileSystemInterface::class)) {
        interface FileSystemInterface {
            public function mkdir($uri, $mode = NULL, $recursive = FALSE, $context = NULL);
            public function delete($path);
            public function deleteRecursive($path, ?callable $callback = NULL);
            public function copy($source, $destination, $fileExists = NULL);
            public function move($source, $destination, $fileExists = NULL);
            public function prepareDirectory(&$directory, $options = 3);
            public function dirname($uri);
            public function chmod($uri, $mode = NULL);
        }
    }
}

namespace Drupal\Core\Site {
    if (!class_exists(Settings::class)) {
        final class Settings {
            private static $instance;
            private array $storage;

            public function __construct(array $settings) {
                self::$instance = $this;
                $this->storage = $settings;
            }

            public static function getInstance() {
                if (self::$instance === NULL) {
                    throw new \BadMethodCallException('Settings::$instance is not initialized yet.');
                }
                return self::$instance;
            }

            public static function get($name, $default = NULL) {
                return self::getInstance()->storage[$name] ?? $default;
            }

            public static function getHashSalt() {
                $salt = self::get('hash_salt');
                if (empty($salt)) {
                    throw new \RuntimeException('Missing $settings[\'hash_salt\'] in settings.php.');
                }
                return $salt;
            }
        }
    }
}

namespace Drupal\Core\Form {
    if (!class_exists(FormBase::class)) {
        abstract class FormBase {}
    }
}

namespace Drupal\Core\DependencyInjection {
    if (!class_exists(ContainerBuilder::class)) {
        class ContainerBuilder {
            private array $services = [];

            public function set($id, $service) {
                $this->services[$id] = $service;
            }

            public function get($id) {
                return $this->services[$id] ?? NULL;
            }

            public function has($id) {
                return isset($this->services[$id]);
            }
        }
    }
}

namespace Drupal\Core\StringTranslation {
    if (!trait_exists(StringTranslationTrait::class)) {
        trait StringTranslationTrait {
            protected $stringTranslation;

            protected function t($string, array $args = [], array $options = []) {
                return strtr($string, $args);
            }

            public function setStringTranslation($translation) {
                $this->stringTranslation = $translation;
                return $this;
            }
        }
    }
}

namespace Drupal\Core\Messenger {
    if (!interface_exists(MessengerInterface::class)) {
        interface MessengerInterface {
            public function addMessage($message, $type = 'status', $repeat = FALSE);
            public function addStatus($message, $repeat = FALSE);
            public function addError($message, $repeat = FALSE);
            public function addWarning($message, $repeat = FALSE);
            public function all();
            public function messagesByType($type);
            public function deleteAll();
            public function deleteByType($type);
        }
    }
}

namespace Drupal\Core\Session {
    if (!interface_exists(AccountInterface::class)) {
        interface AccountInterface {
            public function id();
            public function getRoles($exclude_locked_roles = FALSE);
            public function hasPermission(string $permission);
            public function hasRole(string $rid);
            public function isAuthenticated();
            public function isAnonymous();
            public function getPreferredLangcode($fallback_to_default = TRUE);
            public function getPreferredAdminLangcode($fallback_to_default = TRUE);
            public function getAccountName();
            public function getDisplayName();
            public function getEmail();
            public function getTimeZone();
            public function getLastAccessedTime();
        }
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
            public function getViewBuilder($entity_type_id);
        }
    }
    if (!interface_exists(EntityViewBuilderInterface::class)) {
        interface EntityViewBuilderInterface {
            public function view($entity, $view_mode = 'full', $langcode = NULL);
        }
    }
    if (!interface_exists(EntityTypeInterface::class)) {
        interface EntityTypeInterface {
            public function getKey($key);
        }
    }
    if (!interface_exists(EntityInterface::class)) {
        interface EntityInterface {
            public function label();
            public function hasLinkTemplate($rel);
            public function getEntityType();
            public function language();
            public function getEntityTypeId();
        }
    }
}

namespace Drupal\Core\Render {
    if (!interface_exists(RendererInterface::class)) {
        interface RendererInterface {
            public function renderInIsolation(&$elements);
        }
    }
}

namespace Drupal\Core\Language {
    if (!interface_exists(LanguageInterface::class)) {
        interface LanguageInterface {
            const TYPE_CONTENT = 'language_content';
            const TYPE_INTERFACE = 'language_interface';
            const TYPE_URL = 'language_url';

            public function getId();
        }
    }
    if (!interface_exists(LanguageManagerInterface::class)) {
        interface LanguageManagerInterface {
            public function getCurrentLanguage($type = LanguageInterface::TYPE_INTERFACE);
        }
    }
}

namespace Drupal\Core\Routing {
    if (!interface_exists(UrlGeneratorInterface::class)) {
        interface UrlGeneratorInterface {
            public function generateFromRoute(string $name, array $parameters = [], array $options = [], bool $collect_bubbleable_metadata = FALSE);
        }
    }
}

namespace Drupal\Core\File {
    if (!interface_exists(FileUrlGeneratorInterface::class)) {
        interface FileUrlGeneratorInterface {
            public function generateString(string $uri): string;
        }
    }
}

namespace Drupal\Core\Cache\Context {
    if (!class_exists(CacheContextsManager::class)) {
        class CacheContextsManager {
            public function assertValidTokens($context_tokens) {
                return TRUE;
            }
        }
    }
}

namespace Drupal\Core\Access {
    if (!interface_exists(AccessResultInterface::class)) {
        interface AccessResultInterface {
            public function isAllowed();
            public function isForbidden();
            public function isNeutral();
        }
    }
    if (!class_exists(AccessResult::class)) {
        class AccessResult implements AccessResultInterface {
            private string $state;
            private array $cacheContexts = [];

            private function __construct(string $state) {
                $this->state = $state;
            }

            public static function neutral($reason = NULL) {
                return new self('neutral');
            }

            public static function allowed() {
                return new self('allowed');
            }

            public static function forbidden($reason = NULL) {
                return new self('forbidden');
            }

            public static function allowedIf(bool $condition, ?string $reason = NULL) {
                return $condition ? static::allowed() : static::neutral($reason);
            }

            public static function allowedIfHasPermission($account, $permission) {
                $result = static::allowedIf($account->hasPermission($permission));
                $result->addCacheContexts(['user.permissions']);
                return $result;
            }

            public function addCacheContexts(array $contexts) {
                $this->cacheContexts = array_unique(array_merge($this->cacheContexts, $contexts));
                return $this;
            }

            public function getCacheContexts() {
                return $this->cacheContexts;
            }

            public function isAllowed() {
                return $this->state === 'allowed';
            }

            public function isForbidden() {
                return $this->state === 'forbidden';
            }

            public function isNeutral() {
                return $this->state === 'neutral';
            }
        }
    }
}

namespace Drupal\Core\Block {
    if (!class_exists(BlockBase::class)) {
        abstract class BlockBase {
            use \Drupal\Core\StringTranslation\StringTranslationTrait;

            protected $configuration;
            protected $pluginId;
            protected $pluginDefinition;

            public function __construct(array $configuration, $plugin_id, $plugin_definition) {
                $this->configuration = $configuration;
                $this->pluginId = $plugin_id;
                $this->pluginDefinition = $plugin_definition;
            }
        }
    }
}

namespace Drupal\Core\Cache {
    if (!class_exists(CacheableMetadata::class)) {
        class CacheableMetadata {
            private array $tags = [];
            private array $contexts = [];

            public static function createFromRenderArray(array $build) {
                $metadata = new self();
                $metadata->tags = $build['#cache']['tags'] ?? [];
                $metadata->contexts = $build['#cache']['contexts'] ?? [];
                return $metadata;
            }

            public function addCacheTags(array $tags) {
                $this->tags = array_unique(array_merge($this->tags, $tags));
                return $this;
            }

            public function addCacheContexts(array $contexts) {
                $this->contexts = array_unique(array_merge($this->contexts, $contexts));
                return $this;
            }

            public function addCacheableDependency($other_object) {
                if (method_exists($other_object, 'getCacheContexts')) {
                    $this->addCacheContexts($other_object->getCacheContexts());
                }
                if (method_exists($other_object, 'getCacheTags')) {
                    $this->addCacheTags($other_object->getCacheTags());
                }
                return $this;
            }

            public function merge($other) {
                $metadata = new self();
                $metadata->tags = array_unique(array_merge($this->tags, $other->tags ?? []));
                $metadata->contexts = array_unique(array_merge($this->contexts, $other->contexts ?? []));
                return $metadata;
            }

            public function applyTo(array &$build) {
                $build['#cache']['tags'] = $this->tags;
                $build['#cache']['contexts'] = $this->contexts;
            }
        }
    }
}

namespace Drupal\Core {
    if (!class_exists(Url::class)) {
        class Url {
            private function __construct(private string $routeName) {}

            public static function fromRoute($route_name, $route_parameters = [], $options = []) {
                return new self($route_name);
            }

            public function toString($collect_bubbleable_metadata = FALSE) {
                return \Drupal::service('url_generator')->generateFromRoute($this->routeName);
            }
        }
    }
}

namespace Drupal\Core\TypedData {
    if (!interface_exists(ComplexDataInterface::class)) {
        interface ComplexDataInterface {
            public function getValue();
        }
    }
}

namespace Drupal\Component\Render {
    if (!class_exists(PlainTextOutput::class)) {
        class PlainTextOutput {
            public static function renderFromHtml($string) {
                return html_entity_decode(strip_tags((string) $string), ENT_QUOTES);
            }
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
