<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Tag1\Scolta\Index\StatusReport;

/**
 * Decides whether a failed build segment should be resumed or end the chain.
 *
 * A build too large for one process yields on memory pressure and is continued
 * in a fresh one. The parent driving those segments sees only an exit status,
 * and every failure exits non-zero — so a voluntary yield that wants another
 * segment and a merge that found the index corrupt look identical from there.
 *
 * The segment itself records which it was (BuildState::recordOutcome(), from
 * scolta-php), and this turns that record into the one decision the driver
 * has to make. It reads no files and spawns no processes: the caller does the
 * I/O and hands the result here, which is what makes the rule testable without
 * a site, a database, or a drush binary.
 */
final class ResumeChainPolicy {

  /**
   * Constructs the policy.
   *
   * @param string|null $memoryLimit
   *   The PHP memory_limit to quote in memory-related remediation, or NULL
   *   when it cannot be read.
   */
  public function __construct(private readonly ?string $memoryLimit = NULL) {
  }

  /**
   * Why the chain must stop after this segment, or NULL to run another.
   *
   * Only called for a segment that exited non-zero.
   *
   * @param array|null $outcome
   *   What the segment recorded on its way out (BuildState::readOutcome()),
   *   or NULL when it recorded nothing — an OOM kill, a fatal, a signal.
   * @param int $pagesCommitted
   *   Pages the shared build manifest shows committed now.
   * @param int $pagesBefore
   *   Pages it showed before this segment ran.
   * @param int $segment
   *   Segment number, 1-based, for the message.
   *
   * @return string|null
   *   The failure to report, or NULL when the segment yielded for memory and
   *   made progress, so another segment is worth running.
   */
  public function failureReason(?array $outcome, int $pagesCommitted, int $pagesBefore, int $segment): ?string {
    // A segment that recorded anything other than a memory yield has decided
    // this build is broken. Resuming re-walks the whole corpus to reach the
    // same error, so the chain stops here and reports what actually failed.
    if ($outcome !== NULL && ($outcome['error'] ?? NULL) !== StatusReport::MEMORY_ABORT) {
      return sprintf(
        'The build failed in segment %d and the index has not been republished: %s',
        $segment,
        // A segment that recorded success and still exited non-zero failed
        // after its build returned — publishing, verifying, shutting down.
        $outcome['error'] ?? 'the segment reported a successful build and then exited non-zero; see its output above.',
      );
    }

    // Either the segment yielded for memory, or it died without recording
    // anything at all. Both leave progress as the evidence, and a segment that
    // committed nothing will do the same again.
    if ($pagesCommitted <= $pagesBefore) {
      return sprintf(
        'The build stalled at %d pages: segment %d committed nothing before hitting the memory limit again. '
        . 'The index has not been republished. Raise PHP memory_limit (currently %s) or lower --chunk-size, '
        . 'then re-run with --restart.',
        $pagesCommitted,
        $segment,
        $this->memoryLimit ?? 'unknown',
      );
    }

    return NULL;
  }

}
