<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\scolta\Service\ScoltaContentGatherer;
use PHPUnit\Framework\TestCase;

/**
 * Structural test for ScoltaContentGatherer's streaming resume contract.
 *
 * The gatherer's behavior (entity queries, translations, field mappings, the
 * manifest round-trip) is exercised functionally by the pipeline tests under
 * tests/src/Functional/.
 */
class ScoltaContentGathererTest extends TestCase {

  /**
   * gather() streams and resumes by entity ID.
   *
   * The fourth parameter is a resume boundary expressed as an entity ID, not
   * a row offset: the build manifest counts pages while this walk counts
   * entities, and an entity yields a page per translation, so a page count in
   * this position skipped the wrong rows by the translation factor.
   */
  public function testGatherMethodSignature(): void {
    $ref = new \ReflectionMethod(ScoltaContentGatherer::class, 'gather');
    $this->assertTrue($ref->isPublic());
    $this->assertSame(\Generator::class, (string) $ref->getReturnType(),
      'gather() must return a \Generator — the corpus streams, never pre-loads');

    $params = $ref->getParameters();
    $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $params);
    $this->assertSame(
      ['entityType', 'bundle', 'siteName', 'resumeFromId', 'manifest', 'force'],
      $names
    );

    $this->assertSame('string', (string) $params[0]->getType());
    $this->assertSame('string', (string) $params[1]->getType());
    $this->assertSame('string', (string) $params[2]->getType());
    $this->assertSame('string|int|null', (string) $params[3]->getType(),
      'The resume boundary is an entity ID, nullable when starting from the top');
    $this->assertSame('?Tag1\Scolta\Index\TimestampManifest', (string) $params[4]->getType(),
      'gather() must accept an optional TimestampManifest for incremental builds');
  }

}
