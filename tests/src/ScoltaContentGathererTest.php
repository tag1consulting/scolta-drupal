<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\scolta\Service\ScoltaContentGatherer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Structural tests for ScoltaContentGatherer.
 *
 * Verifies the service API surface via reflection and the container wiring
 * via parsed YAML. The gatherer's behavior (entity queries, translations,
 * field mappings, the manifest round-trip) is exercised functionally by the
 * pipeline tests under tests/src/Functional/.
 */
class ScoltaContentGathererTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // Class structure.
  // -------------------------------------------------------------------

  public function testGathererFileExists(): void {
    $this->assertFileExists(
      $this->moduleRoot . '/src/Service/ScoltaContentGatherer.php',
      'ScoltaContentGatherer.php must exist in src/Service/'
    );
  }

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

  public function testGatherCountMethodExists(): void {
    $ref = new \ReflectionMethod(ScoltaContentGatherer::class, 'gatherCount');
    $this->assertTrue($ref->isPublic());
    $this->assertSame('int', (string) $ref->getReturnType());
  }

  public function testGetEntityTimestampsMethodExists(): void {
    $this->assertTrue(
      method_exists(ScoltaContentGatherer::class, 'getEntityTimestamps'),
      'ScoltaContentGatherer must have getEntityTimestamps() for lightweight timestamp queries'
    );
  }

  public function testGatherByIdsMethodExists(): void {
    $this->assertTrue(
      method_exists(ScoltaContentGatherer::class, 'gatherByIds'),
      'ScoltaContentGatherer must have gatherByIds() — the shared ID-scoped pipeline'
    );
  }

  // -------------------------------------------------------------------
  // Service container registration.
  // -------------------------------------------------------------------

  public function testServiceIsRegisteredInServicesYml(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml');
    $this->assertArrayHasKey('scolta.content_gatherer', $services['services'] ?? [],
      'scolta.content_gatherer service must be defined in scolta.services.yml');
  }

  public function testServiceClassInServicesYml(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml');
    $this->assertSame(
      ScoltaContentGatherer::class,
      $services['services']['scolta.content_gatherer']['class'] ?? NULL,
      'scolta.content_gatherer must reference ScoltaContentGatherer class'
    );
  }

  public function testServiceArgumentIsEntityTypeManager(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml');
    $arguments = $services['services']['scolta.content_gatherer']['arguments'] ?? [];
    $this->assertContains('@entity_type.manager', $arguments,
      'scolta.content_gatherer must inject @entity_type.manager');
  }

  // -------------------------------------------------------------------
  // Injection into ScoltaCommands.
  // -------------------------------------------------------------------

  public function testDrushServicesYmlInjectsGathererIntoCommands(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $arguments = $drush['services']['scolta.commands']['arguments'] ?? [];
    $this->assertContains('@scolta.content_gatherer', $arguments,
      'drush.services.yml must pass @scolta.content_gatherer to ScoltaCommands');
  }

  // -------------------------------------------------------------------
  // Hook API documentation (scolta.api.php).
  // -------------------------------------------------------------------

  public function testScoltaApiPhpExists(): void {
    $this->assertFileExists(
      $this->moduleRoot . '/scolta.api.php',
      'scolta.api.php must exist for hook discoverability (standard Drupal practice)'
    );
  }

}
