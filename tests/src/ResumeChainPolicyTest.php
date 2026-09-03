<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\scolta\Service\ResumeChainPolicy;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\StatusReport;

/**
 * The resume chain must stop on a failure that resuming cannot fix.
 *
 * Observed in production: a segment's merge failed with "Duplicate page
 * ordinal 13650 across chunks", the driver read the non-zero exit as another
 * memory yield, ran one more full-corpus segment to reach the same error, then
 * reported the build as stalled on memory and told the operator to raise
 * memory_limit. The real error reached the log only because the child's output
 * is echoed.
 *
 * @group scolta
 *
 * @coversDefaultClass \Drupal\scolta\Service\ResumeChainPolicy
 */
class ResumeChainPolicyTest extends TestCase {

  /**
   * The production error, as a segment would record it.
   */
  private const MERGE_ERROR = 'Duplicate page ordinal 13650 across chunks: "139995" and "155869" both claim it.';

  /**
   * A merge failure ends the chain, named as itself.
   */
  public function testIntegrityFailureEndsTheChainWithThatError(): void {
    $reason = $this->policy()->failureReason(
      ['success' => FALSE, 'error' => self::MERGE_ERROR],
      // Pages committed rose: the segment indexed its share and then failed
      // its merge, which is exactly what made progress look like a reason to
      // keep going.
      pagesCommitted: 119854,
      pagesBefore: 119077,
      segment: 1,
    );

    $this->assertNotNull($reason, 'A segment that failed its merge must end the chain');
    $this->assertStringContainsString(self::MERGE_ERROR, $reason,
      'The operator must be told what actually failed');
    $this->assertStringNotContainsString('memory_limit', $reason,
      'Memory remediation for a non-memory failure is the misdiagnosis this fixes');
  }

  /**
   * A memory yield that committed pages runs another segment.
   */
  public function testMemoryYieldThatCommittedPagesResumes(): void {
    $reason = $this->policy()->failureReason(
      ['success' => FALSE, 'error' => StatusReport::MEMORY_ABORT],
      pagesCommitted: 119854,
      pagesBefore: 119077,
      segment: 1,
    );

    $this->assertNull($reason);
  }

  /**
   * A memory yield that committed nothing is a stall, not another segment.
   */
  public function testMemoryYieldThatCommittedNothingStopsWithMemoryAdvice(): void {
    $reason = $this->policy()->failureReason(
      ['success' => FALSE, 'error' => StatusReport::MEMORY_ABORT],
      pagesCommitted: 119077,
      pagesBefore: 119077,
      segment: 2,
    );

    $this->assertNotNull($reason);
    $this->assertStringContainsString('stalled at 119077 pages', $reason);
    $this->assertStringContainsString('memory_limit (currently 4096M)', $reason,
      'The advice must quote the limit the build actually ran under');
  }

  /**
   * A segment killed before it could record anything falls back to progress.
   */
  public function testSegmentThatRecordedNothingFallsBackToProgress(): void {
    $policy = $this->policy();

    $this->assertNull($policy->failureReason(NULL, 119854, 119077, 1),
      'An OOM-killed segment that still committed pages is worth resuming');
    $this->assertStringContainsString('stalled at 119077 pages',
      (string) $policy->failureReason(NULL, 119077, 119077, 1));
  }

  /**
   * A segment that recorded success and still exited non-zero stops the chain.
   */
  public function testRecordedSuccessWithNonZeroExitStopsTheChain(): void {
    $reason = $this->policy()->failureReason(
      ['success' => TRUE, 'error' => NULL],
      pagesCommitted: 120000,
      pagesBefore: 119077,
      segment: 1,
    );

    $this->assertNotNull($reason, 'Looping on this would re-walk the corpus for nothing');
    $this->assertStringContainsString('exited non-zero', $reason);
  }

  /**
   * A policy quoting the memory limit the production build ran under.
   */
  private function policy(): ResumeChainPolicy {
    return new ResumeChainPolicy('4096M');
  }

}
