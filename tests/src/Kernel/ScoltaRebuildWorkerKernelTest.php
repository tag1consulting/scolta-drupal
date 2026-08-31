<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Plugin\QueueWorker\ScoltaRebuildWorker;

/**
 * ScoltaRebuildWorker's debounce and lock behavior, via the real container.
 *
 * Both tests return before any content gathering: the debounce check and
 * the build-lock acquisition happen before the pipeline runs, so this needs
 * no search_api server, no node content, and no scolta-php pipeline classes
 * — only the queue worker plugin, real state, and a real lock. Pipeline
 * parity with `drush scolta:build` is covered by the functional tests
 * (PipelineParityFunctionalTest and friends).
 *
 * @group scolta
 */
class ScoltaRebuildWorkerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta'];

  /**
   * Builds the worker through the real plugin manager, injected like Drupal.
   */
  protected function worker(): ScoltaRebuildWorker {
    return $this->container->get('plugin.manager.queue_worker')->createInstance('scolta_rebuild');
  }

  /**
   * A content change inside the debounce window suspends the queue.
   */
  public function testFreshContentChangeSuspendsTheQueue(): void {
    // 1 second ago is well inside any configured delay (floor 60s,
    // fallback 300s).
    \Drupal::state()->set('scolta.rebuild_requested_at', time() - 1);

    $this->expectException(SuspendQueueException::class);
    $this->expectExceptionMessageMatches('/Debouncing/');
    $this->worker()->processItem(['type' => 'auto']);
  }

  /**
   * With no recorded change, the debounce does not delay the initial build.
   */
  public function testDebounceSkippedWhenNoChangeRecorded(): void {
    // No recorded change (e.g. the install-time queue item): the debounce
    // must not delay the initial build. KernelTestBase swaps the container's
    // 'lock' service for a NullLockBackend that never contends, for test
    // speed and isolation — but the worker's own lock-acquisition failure is
    // real production code, so this test opts back into the real
    // DatabaseLockBackend to exercise it. A second, independent lock object
    // sharing the same connection simulates another process holding the
    // build lock, forcing the worker's own acquire() to fail for real —
    // proving the code reached lock acquisition rather than suspending on
    // the debounce check.
    $this->container->set('lock', new DatabaseLockBackend($this->container->get('database')));

    $externalLock = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($externalLock->acquire('scolta_build', 300));

    try {
      $this->worker()->processItem(['type' => 'install']);
      $this->fail('Expected SuspendQueueException from the held lock');
    }
    catch (SuspendQueueException $e) {
      $this->assertStringContainsString(
        'lock', $e->getMessage(),
        'With no recorded change the worker must reach lock acquisition, not the debounce'
      );
    }
    finally {
      $externalLock->release('scolta_build');
    }
  }

  /**
   * The worker is discovered and built through container-injected DI.
   */
  public function testWorkerImplementsContainerFactoryPluginInterface(): void {
    $this->assertInstanceOf(ContainerFactoryPluginInterface::class, $this->worker());
  }

}
