<?php

declare(strict_types=1);

namespace Drupal\scolta\Progress;

use Drupal\Core\Lock\LockBackendInterface;
use Tag1\Scolta\Index\ProgressReporterInterface;

/**
 * Renews the build lock at every chunk boundary.
 *
 * A full build on a six-figure corpus can run longer than any fixed lock
 * timeout that is also short enough to recover promptly from a crashed build.
 * Picking one number trades a stuck queue against two concurrent builds
 * writing the same output directory.
 *
 * The orchestrator already calls advance() once per committed chunk, so the
 * lock can simply be re-acquired there: Drupal's lock backends extend the
 * expiry when the caller is the process that already holds the lock. The
 * timeout then only has to outlive a single chunk rather than the whole
 * build, so a crashed build frees the lock in seconds while a healthy long
 * build never loses it.
 *
 * @since 1.2.0
 * @stability experimental
 */
class LockRenewingProgressReporter implements ProgressReporterInterface {

  /**
   * Constructs a LockRenewingProgressReporter.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend holding the build lock.
   * @param string $lockName
   *   The name of the lock to renew.
   * @param float $timeout
   *   The lock lease, in seconds, renewed on every advance().
   */
  public function __construct(
    private readonly LockBackendInterface $lock,
    private readonly string $lockName,
    private readonly float $timeout = 300.0,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function start(int $totalSteps, string $label): void {
    $this->renew();
  }

  /**
   * {@inheritdoc}
   */
  public function advance(int $steps = 1, ?string $detail = NULL): void {
    $this->renew();
  }

  /**
   * {@inheritdoc}
   */
  public function finish(?string $summary = NULL): void {
    // The caller releases the lock in its own finally block; renewing here
    // would only widen the window in which a crash leaves it held.
  }

  /**
   * Extend the lease on a lock this process already holds.
   */
  private function renew(): void {
    // acquire() on a held lock updates its expiry and returns TRUE. A FALSE
    // return means the lease already lapsed and someone else took it; there
    // is nothing useful to do about that here, and the build's own output is
    // still guarded by the atomic directory swap in the writer.
    $this->lock->acquire($this->lockName, $this->timeout);
  }

}
