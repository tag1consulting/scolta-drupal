<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\scolta\Commands\ScoltaCommands;
use Drush\Log\DrushLoggerManager;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\StatusReport;

/**
 * The resume chain must stop on a failure that resuming cannot fix.
 *
 * Observed in production: a segment's merge failed with "Duplicate page
 * ordinal 13650 across chunks", the parent read the non-zero exit as another
 * memory yield, ran one more full-corpus segment to reach the same error,
 * then reported the build as stalled on memory and told the operator to raise
 * memory_limit. The real error reached the log only because the child's
 * output was echoed.
 *
 * @group scolta
 */
class ResumeChainClassificationTest extends TestCase {

  /**
   * Build state directory the scripted segments write into.
   */
  private string $stateDir;

  /**
   * Index output directory the chain would publish to.
   */
  private string $outputDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $uid = uniqid('', TRUE);
    $this->stateDir = sys_get_temp_dir() . "/scolta-chain-state-{$uid}";
    $this->outputDir = sys_get_temp_dir() . "/scolta-chain-out-{$uid}";
    mkdir($this->stateDir, 0755, TRUE);
    mkdir($this->outputDir, 0755, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ([$this->stateDir, $this->outputDir] as $dir) {
      foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
          unlink($file);
        }
      }
      if (is_dir($dir)) {
        rmdir($dir);
      }
    }
  }

  /**
   * A merge/integrity failure ends the chain, named as itself.
   */
  public function testIntegrityFailureEndsTheChainWithThatError(): void {
    $error = 'Duplicate page ordinal 13650 across chunks: "139995" and "155869" both claim it.';
    $commands = $this->chainDriver([
      ['error' => $error, 'pages' => 119854],
    ]);

    try {
      $this->runChain($commands, pagesBefore: 119077);
      $this->fail('A segment that failed its merge must end the chain');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString($error, $e->getMessage(),
        "The operator must be told what actually failed");
      $this->assertStringNotContainsString('memory_limit', $e->getMessage(),
        'Memory remediation for a non-memory failure is the misdiagnosis this fixes');
    }

    $this->assertSame(1, $commands->segmentsRun,
      'The chain must not spend another full-corpus segment reaching the same error');
  }

  /**
   * A memory yield still resumes: the chain only stops on real failures.
   */
  public function testMemoryYieldThatCommittedPagesRunsAnotherSegment(): void {
    $commands = $this->chainDriver([
      ['error' => StatusReport::MEMORY_ABORT, 'pages' => 119854],
      ['error' => NULL, 'pages' => 120000],
    ]);

    $this->runChain($commands, pagesBefore: 119077);

    $this->assertSame(2, $commands->segmentsRun);
  }

  /**
   * A segment killed before it could report falls back to progress.
   */
  public function testSegmentThatDiesWithoutReportingIsStillCaught(): void {
    // No outcome recorded and no pages committed: an OOM kill, a fatal, a
    // signal. Nothing on disk but the manifest, so the manifest decides.
    $commands = $this->chainDriver([['error' => FALSE, 'pages' => 119077]]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/stalled at 119077 pages/');
    $this->runChain($commands, pagesBefore: 119077);
  }

  /**
   * Drive the chain against a scripted sequence of segment outcomes.
   */
  private function runChain(ScoltaCommands $commands, int $pagesBefore): void {
    $report = new StatusReport(
      version: '1.0.0',
      pagefindVersion: '1.0.0',
      resolvedIndexer: 'php',
      pagesProcessed: $pagesBefore,
      chunksWritten: 12,
      peakMemoryBytes: 0,
      memoryBudgetBytes: 0,
      durationSeconds: 0.0,
      outputDir: $this->outputDir,
      success: FALSE,
      error: StatusReport::MEMORY_ABORT,
    );

    $method = new \ReflectionMethod(ScoltaCommands::class, 'runResumeChain');
    $method->invoke($commands, [], 4096 * 1_048_576, $report, $this->stateDir, $this->outputDir);
  }

  /**
   * A ScoltaCommands whose segments are scripted rather than spawned.
   *
   * @param list<array{error: string|null|false, pages: int}> $segments
   *   One entry per segment: the error the segment records (NULL for success,
   *   FALSE for a segment that dies without recording anything) and the pages
   *   the shared manifest shows committed when it exits.
   */
  private function chainDriver(array $segments): ScoltaCommands {
    $commands = new class($segments, $this->stateDir) extends ScoltaCommands {

      /**
       * How many segments the chain ran.
       */
      public int $segmentsRun = 0;

      /**
       * Constructs the scripted driver.
       *
       * @param array $segments
       *   The scripted per-segment outcomes.
       * @param string $dir
       *   The build state directory.
       */
      public function __construct(private array $segments, private string $dir) {
        $this->setLogger(new DrushLoggerManager());
      }

      /**
       * {@inheritdoc}
       */
      protected function findDrushBin(): ?string {
        return '/bin/true';
      }

      /**
       * Record what the scripted segment did instead of spawning drush.
       */
      protected function runForeground(string $cmd): int {
        $segment = $this->segments[$this->segmentsRun] ?? ['error' => FALSE, 'pages' => 0];
        $this->segmentsRun++;

        file_put_contents($this->dir . '/manifest.json', json_encode([
          'status' => 'building',
          'pages_processed' => $segment['pages'],
        ]));

        if ($segment['error'] !== FALSE) {
          (new BuildState($this->dir))->recordOutcome(
            $segment['error'] === NULL,
            $segment['error'],
            $segment['pages'],
          );
        }

        return $segment['error'] === NULL ? 0 : 1;
      }

      /**
       * {@inheritdoc}
       */
      protected function confirmChainComplete(string $outputDir, int $segments): void {
      }

    };

    return $commands;
  }

}
