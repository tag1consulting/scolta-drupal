<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The scolta-php floor must refuse a release this module cannot run on.
 *
 * This module required tag1/scolta-php as ^1.1.0 while
 * src/AiProvider/Amazee/DrupalConfigStorage.php declared `implements
 * ProvenanceAwareConfigStorageInterface` and typed two of its methods to
 * AmazeeConnectionSource. Neither symbol exists in the 1.1.0 release, and an
 * `implements` clause is resolved when the class is defined, not when a method
 * is called: `composer require tag1/scolta-drupal:dev-main` resolved
 * scolta-php 1.1.0 and fatalled on the first request that touched the settings
 * form. Both repositories are public, so anyone could reach that state.
 *
 * Nothing caught it, because every CI job here overwrote the constraint with
 * `dev-main@dev` before running composer. The guard is written at the level the
 * defect lives at: what the shipped file says, evaluated against the release
 * that cannot satisfy it.
 */
class ScoltaPhpFloorTest extends TestCase {

  /**
   * Every scolta-php symbol this module resolves at class-definition time.
   *
   * Listed rather than counted, because the point of the floor is which
   * symbols it guarantees. A class_exists() guarded use, like the incremental
   * updater in ScoltaRebuildWorker, is deliberately absent: that path is
   * dormant on an older library instead of fatal on it.
   */
  private const REQUIRES_1_2 = [
    'Tag1\Scolta\AiProvider\Amazee\ProvenanceAwareConfigStorageInterface',
    'Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource',
  ];

  /**
   * The constraint composer.json states for tag1/scolta-php.
   */
  private function declaredConstraint(): string {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), TRUE);
    $this->assertIsArray($manifest, 'composer.json must parse');
    $this->assertArrayHasKey('tag1/scolta-php', $manifest['require'] ?? [], 'composer.json must require tag1/scolta-php');
    return (string) $manifest['require']['tag1/scolta-php'];
  }

  /**
   * Does a constraint admit the 1.1.0 release?
   *
   * Hand-written rather than delegated to composer/semver, which reaches this
   * package only as a transitive dependency of drupal/core and is absent from
   * the unit-test job, where core is provided rather than installed. The
   * question is narrow enough to answer exactly: a branch constraint names a
   * branch and can never resolve a tag, and a range constraint admits 1.1.0
   * only if its lower bound is below 1.2.
   */
  private function admitsRelease(string $constraint, int $major, int $minor): bool {
    $c = trim($constraint);
    // dev-main, dev-main@dev, @dev: a branch, which no tag satisfies.
    if (str_starts_with($c, 'dev-') || $c === '@dev') {
      return FALSE;
    }
    // Strip a stability suffix; it narrows what may be installed, never widens
    // which release line the constraint covers.
    $c = (string) preg_replace('/@\w+$/', '', $c);
    if (!preg_match('/^[\^~]?(\d+)\.(\d+)/', $c, $m)) {
      // Anything this cannot read is reported as admitting the release, so an
      // unrecognised constraint fails the test rather than passing it.
      return TRUE;
    }
    $floorMajor = (int) $m[1];
    $floorMinor = (int) $m[2];
    return $floorMajor < $major || ($floorMajor === $major && $floorMinor <= $minor);
  }

  /**
   * The shipped constraint must not resolve the 1.1.0 release.
   */
  public function testTheDeclaredFloorRefusesScoltaPhp110(): void {
    $constraint = $this->declaredConstraint();
    $this->assertFalse(
      $this->admitsRelease($constraint, 1, 1),
      sprintf(
        'composer.json requires tag1/scolta-php as "%s", which resolves the 1.1.0 release. '
        . 'This module resolves %s at class-definition time, and 1.1.0 has neither, '
        . 'so that resolution is a fatal error on the first request rather than a missing feature.',
        $constraint,
        implode(' and ', self::REQUIRES_1_2),
      ),
    );
  }

  /**
   * The symbols the floor is there for are really loaded unguarded.
   *
   * Without this, the floor could be raised for a reason that has since gone
   * away and nothing would say so. It reads the source rather than reflecting
   * on loaded classes, so it holds whether or not scolta-php is installed.
   */
  public function testTheSymbolsTheFloorExistsForAreStillLoadedUnguarded(): void {
    $storage = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/scolta_ui/src/AiProvider/Amazee/DrupalConfigStorage.php');
    $this->assertStringContainsString(
      'implements ProvenanceAwareConfigStorageInterface',
      $storage,
      'If this class no longer implements the interface, the floor may be reconsidered on its own merits.',
    );
    $this->assertStringContainsString('AmazeeConnectionSource $source', $storage);
  }

  /**
   * The reader itself, since every other assertion here rests on it.
   */
  public function testTheConstraintReaderAgreesWithComposer(): void {
    // Verified against composer 2 in the scolta-fleet repository, with only a
    // v1.1.0 tag present and main aliased to 1.2.x-dev.
    $this->assertTrue($this->admitsRelease('^1.1.0', 1, 1), '^1.1.0 resolves 1.1.0, which is the defect');
    $this->assertFalse($this->admitsRelease('^1.2', 1, 1));
    $this->assertFalse($this->admitsRelease('^1.2@dev', 1, 1));
    $this->assertFalse($this->admitsRelease('dev-main', 1, 1));
    $this->assertFalse($this->admitsRelease('dev-main@dev', 1, 1));
    // An unreadable constraint is reported as admitting the release, so it
    // fails the guard rather than slipping through it.
    $this->assertTrue($this->admitsRelease('whatever', 1, 1));
  }

}
