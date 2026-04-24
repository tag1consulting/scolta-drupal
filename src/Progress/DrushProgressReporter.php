<?php

declare(strict_types=1);

namespace Drupal\scolta\Progress;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Tag1\Scolta\Index\ProgressReporterInterface;

/**
 * Routes IndexBuildOrchestrator progress callbacks to a Symfony ProgressBar.
 *
 * Drush exposes the Symfony Console output interface, so we use a standard
 * Symfony ProgressBar here. This gives operators live chunk-by-chunk feedback
 * during long builds instead of a silent 50-minute wait.
 *
 * @since 0.3.2
 * @stability experimental
 */
class DrushProgressReporter implements ProgressReporterInterface {

  /**
   * The active Symfony ProgressBar, or NULL when no build is running.
   *
   * @var \Symfony\Component\Console\Helper\ProgressBar|null
   */
  private ?ProgressBar $bar = NULL;

  /**
   * Constructs a DrushProgressReporter.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The Drush output interface to write progress to.
   */
  public function __construct(private readonly OutputInterface $output) {}

  /**
   * Start the progress bar.
   *
   * @param int $totalSteps
   *   Total number of steps.
   * @param string $label
   *   Human-readable label.
   */
  public function start(int $totalSteps, string $label): void {
    $this->output->writeln($label . '...');
    $this->bar = new ProgressBar($this->output, $totalSteps);
    $this->bar->start();
  }

  /**
   * Advance the progress bar.
   *
   * @param int $steps
   *   Number of steps completed.
   * @param string|null $detail
   *   Optional chunk detail shown as the progress bar message.
   */
  public function advance(int $steps = 1, ?string $detail = NULL): void {
    if ($detail !== NULL && $this->bar !== NULL) {
      $this->bar->setMessage($detail);
    }
    $this->bar?->advance($steps);
  }

  /**
   * Finish the progress bar and print an optional summary.
   *
   * @param string|null $summary
   *   Optional summary line.
   */
  public function finish(?string $summary = NULL): void {
    $this->bar?->finish();
    $this->bar = NULL;
    $this->output->writeln('');
    if ($summary !== NULL) {
      $this->output->writeln('  ' . $summary);
    }
  }

}
