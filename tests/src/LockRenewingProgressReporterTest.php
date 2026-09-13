<?php

declare(strict_types=1);

// LockBackendInterface is not in this package's vendor; declare the shape the
// reporter needs so it can be built without a Drupal bootstrap.
// phpcs:disable
namespace Drupal\Core\Lock {
    if (!interface_exists(\Drupal\Core\Lock\LockBackendInterface::class)) {
        interface LockBackendInterface {
            public function acquire($name, $timeout = 30.0);
            public function lockMayBeAvailable($name);
            public function wait($name, $delay = 30);
            public function release($name);
            public function releaseAll($lockId = NULL);
            public function getLockId();
        }
    }
}
// phpcs:enable

namespace Drupal\scolta\Tests {

  use Drupal\Core\Lock\LockBackendInterface;
  use Drupal\scolta\Progress\LockRenewingProgressReporter;
  use PHPUnit\Framework\TestCase;
  use Psr\Log\AbstractLogger;

  /**
   * The queue worker's reporter logs how far along the build is.
   */
  class LockRenewingProgressReporterTest extends TestCase {

    public function test_each_chunk_renews_the_lock_and_logs_progress(): void {
      $lock = $this->createMock(LockBackendInterface::class);
      $lock->expects($this->exactly(3))->method('acquire')->with('scolta_build', 42.0)->willReturn(TRUE);

      $lines = [];
      $logger = new class($lines) extends AbstractLogger {

        public function __construct(private array &$lines) {}

        public function log($level, $message, array $context = []): void {
          $this->lines[] = strtr((string) $message, $context);
        }

      };

      $reporter = new LockRenewingProgressReporter($lock, 'scolta_build', 42.0, $logger);
      $reporter->start(4, 'Indexing');
      $reporter->advance(1, 'Chunk 0 (100 pages)');
      $reporter->advance(1, 'Chunk 1 (200 pages)');
      $reporter->finish('200 pages indexed');

      $this->assertSame([
        'Indexing: 4 chunks to build.',
        'Progress 1/4 chunks (25%) Chunk 0 (100 pages)',
        'Progress 2/4 chunks (50%) Chunk 1 (200 pages)',
        'Finished: 200 pages indexed',
      ], $lines);
    }

  }
}
