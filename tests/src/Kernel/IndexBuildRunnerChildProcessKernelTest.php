<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Consolidation\SiteProcess\ProcessBase;
use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Service\IndexBuildRunner;
use Symfony\Component\Console\Output\BufferedOutput;
use Tag1\Scolta\Index\ResumeChainRunner;

/**
 * IndexBuildRunner::runDrush() around a child that is not drush.
 *
 * Everything but the one line that asks Drush for the process: the
 * environment reaches the child, its output is relayed as it runs, its exit
 * code comes back, and the keep-alive callback fires while it runs.
 *
 * @group scolta
 */
class IndexBuildRunnerChildProcessKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta'];

  /**
   * The env and exit code round-trip through a real child process.
   */
  public function testEnvOutputAndExitCodeRoundTrip(): void {
    $output = new BufferedOutput();
    $runner = $this->runner('echo "segment=$SCOLTA_RESUME_SEGMENT"; exit 3', $output);

    $ticks = 0;
    $code = $runner->runDrush('scolta:build', ['resume' => TRUE], [ResumeChainRunner::SEGMENT_ENV => '1'], function () use (&$ticks): void {
      $ticks++;
    });

    $this->assertSame(3, $code, 'The child exit code is what the chain decides on');
    $this->assertStringContainsString('segment=1', $output->fetch(), 'The segment flag reached the child and its output was relayed');
    $this->assertSame(0, $ticks, 'A child that exits within a second is not long enough for a keep-alive');
  }

  /**
   * A child killed by a signal reports failure, never success.
   */
  public function testAKilledChildIsAFailure(): void {
    $runner = $this->runner('kill -9 $$', new BufferedOutput());
    $this->assertNotSame(0, $runner->runDrush('scolta:build', [], []));
  }

  /**
   * A runner whose child is a shell command instead of drush.
   */
  protected function runner(string $shell, BufferedOutput $output): IndexBuildRunner {
    $c = $this->container;
    $runner = new class(
      $c->get('config.factory'),
      $c->get('file_system'),
      $c->get('stream_wrapper_manager'),
      $c->get('scolta.content_gatherer'),
    ) extends IndexBuildRunner {

      /**
       * The shell command standing in for drush.
       */
      public string $shell = '';

      /**
       * Where the child's realtime output goes.
       */
      public BufferedOutput $output;

      /**
       * {@inheritdoc}
       */
      protected function process(string $command, array $options): ProcessBase {
        $process = new ProcessBase(['sh', '-c', $this->shell]);
        $process->setRealtimeOutput($this->output);
        return $process;
      }

    };
    $runner->shell = $shell;
    $runner->output = $output;
    return $runner;
  }

}
