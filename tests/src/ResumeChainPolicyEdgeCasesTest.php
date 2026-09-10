<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\ResumeChainPolicy;

/**
 * Cases the module's former ResumeChainPolicy test covered that scolta-php's does not.
 *
 * The rule lives in scolta-php now; these two assertions came from a
 * production incident the module's copy was written for and belong upstream
 * in tests/Index/ResumeChainPolicyTest.php. Kept here until they move.
 *
 * @group scolta
 */
class ResumeChainPolicyEdgeCasesTest extends TestCase {

  /**
   * A non-memory failure is named as itself, with no memory advice attached.
   */
  public function testIntegrityFailureCarriesNoMemoryAdvice(): void {
    $error = 'Duplicate page ordinal 13650 across chunks: "139995" and "155869" both claim it.';
    $reason = (new ResumeChainPolicy('4096M'))->failureReason(['success' => FALSE, 'error' => $error], 119854, 119077, 1);

    $this->assertStringContainsString($error, (string) $reason);
    $this->assertStringNotContainsString('memory_limit', (string) $reason,
      'Memory remediation for a non-memory failure is the misdiagnosis the policy exists to prevent');
  }

  /**
   * A segment that recorded success and still exited non-zero stops the chain.
   */
  public function testRecordedSuccessWithNonZeroExitStopsTheChain(): void {
    $reason = (new ResumeChainPolicy('4096M'))->failureReason(['success' => TRUE, 'error' => NULL], 120000, 119077, 1);

    $this->assertNotNull($reason, 'Looping on this would re-walk the corpus for nothing');
    $this->assertStringContainsString('exited non-zero', $reason);
  }

}
