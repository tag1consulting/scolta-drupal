<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Proves the settings split is a partition: every key, exactly one home.
 *
 * The split of scolta.settings into a build half and a query half is only
 * safe if it is exhaustive and disjoint. A key in neither object vanishes
 * from an upgrading site; a key in both drifts, and the two copies disagree
 * about how the site searches with nothing to say which is right.
 *
 * Both properties are checkable exhaustively rather than by reading it over,
 * because a config schema is a closed set. That is what these assertions do,
 * so "covers every key" is a proof the suite re-runs rather than a claim made
 * once at review time.
 *
 * The pre-split key set is not read from a file — it is gone from the tree —
 * but it is recorded exactly: its query-time half is
 * _SCOLTA_UPDATE_10006_FRONTEND_KEYS, pinned in scolta.install as the record
 * of what that update moved, and its build-time half is what remains in
 * scolta.settings. Asserting the two schemas against those two halves is the
 * same proof against the same closed set.
 *
 * @see scolta_update_10006()
 */
class ScoltaConfigPartitionTest extends TestCase {

  /**
   * Keys that are new in the split rather than moved by it.
   *
   * The two origin pointers did not exist before, so no upgrading site has a
   * value to migrate into them and the update hook must not name them.
   */
  private const NEW_KEYS = ['index_origin', 'ai_origin'];

  /**
   * Top-level keys of scolta.settings, the build half.
   */
  private function backendSchemaKeys(): array {
    $schema = Yaml::parseFile(PackageManifest::root() . '/config/schema/scolta.schema.yml');
    return array_keys($schema['scolta.settings']['mapping']);
  }

  /**
   * Top-level keys of scolta_ui.settings, the query half.
   */
  private function frontendSchemaKeys(): array {
    $schema = Yaml::parseFile(PackageManifest::root() . '/modules/scolta_ui/config/schema/scolta_ui.schema.yml');
    return array_keys($schema['scolta_ui.settings']['mapping']);
  }

  /**
   * The key list scolta_update_10006() moves, read out of the install file.
   *
   * Parsed from the source rather than reached for as a constant: including
   * scolta.install would run its top-level code in a suite that never boots
   * Drupal, and the point is to check what the shipped file says anyway.
   */
  private function migratedKeys(): array {
    $source = file_get_contents(PackageManifest::root() . '/scolta.install');

    $start = strpos($source, 'const _SCOLTA_UPDATE_10006_FRONTEND_KEYS = [');
    $this->assertNotFalse($start, 'scolta.install must pin the migrated key list');
    $end = strpos($source, '];', $start);
    $this->assertNotFalse($end, 'The pinned key list must be terminated');

    preg_match_all("/'([a-z_]+)'/", substr($source, $start, $end - $start), $matches);

    return $matches[1];
  }

  // -------------------------------------------------------------------
  // Complete and disjoint.
  // -------------------------------------------------------------------

  public function testNoKeyLivesInBothObjects(): void {
    $both = array_intersect($this->backendSchemaKeys(), $this->frontendSchemaKeys());

    $this->assertSame(
      [],
      array_values($both),
      'A key declared by both schemas has two homes and the two copies will drift: ' . implode(', ', $both)
    );
  }

  public function testEveryPreSplitKeyHasExactlyOneHome(): void {
    $backend = $this->backendSchemaKeys();
    $frontend = $this->frontendSchemaKeys();
    $migrated = $this->migratedKeys();

    // The pre-split object is the union of what stayed and what moved.
    $preSplit = array_merge($backend, $migrated);

    foreach ($preSplit as $key) {
      $homes = (int) in_array($key, $backend, TRUE) + (int) in_array($key, $frontend, TRUE);
      $this->assertSame(
        1,
        $homes,
        "Key '{$key}' has {$homes} homes; every pre-split key must be declared by exactly one of the two schemas"
      );
    }

    // And nothing appeared from nowhere: the only keys in either schema that
    // the pre-split object did not have are the two new pointers.
    $appeared = array_diff(array_merge($backend, $frontend), $preSplit);
    $this->assertSame(
      self::NEW_KEYS,
      array_values($appeared),
      'The only keys new in this release are the two origin pointers'
    );
  }

  public function testTheMigratedListIsExactlyTheFrontendSchemaMinusTheNewKeys(): void {
    $expected = array_values(array_diff($this->frontendSchemaKeys(), self::NEW_KEYS));
    $migrated = $this->migratedKeys();

    sort($expected);
    $sortedMigrated = $migrated;
    sort($sortedMigrated);

    $this->assertSame(
      $expected,
      $sortedMigrated,
      'A frontend key the update hook does not move is a key an upgrading site loses; a key it moves that the schema does not declare is a key no site can validate'
    );
  }

  public function testTheMigratedListNamesNoBackendKey(): void {
    $stolen = array_intersect($this->migratedKeys(), $this->backendSchemaKeys());

    $this->assertSame(
      [],
      array_values($stolen),
      'The update must not move a key the backend still owns: ' . implode(', ', $stolen)
    );
  }

  public function testTheMigratedListNamesNoNewKey(): void {
    $new = array_intersect($this->migratedKeys(), self::NEW_KEYS);

    $this->assertSame(
      [],
      array_values($new),
      'An upgrading site has no value to migrate into a key this release introduces: ' . implode(', ', $new)
    );
  }

  // -------------------------------------------------------------------
  // Each module's install defaults match its own schema, exactly.
  // -------------------------------------------------------------------

  public function testEachModuleShipsDefaultsForExactlyItsOwnSchema(): void {
    $objects = [
      'scolta.settings' => [
        $this->backendSchemaKeys(),
        Yaml::parseFile(PackageManifest::root() . '/config/install/scolta.settings.yml'),
      ],
      'scolta_ui.settings' => [
        $this->frontendSchemaKeys(),
        Yaml::parseFile(PackageManifest::root() . '/modules/scolta_ui/config/install/scolta_ui.settings.yml'),
      ],
    ];

    foreach ($objects as $name => [$schemaKeys, $defaults]) {
      $defaultKeys = array_keys($defaults);
      sort($schemaKeys);
      sort($defaultKeys);
      $this->assertSame(
        $schemaKeys,
        $defaultKeys,
        "{$name}: install defaults and schema must declare the same keys — an undeclared default fails config validation, and a declared key with no default is absent on a fresh install"
      );
    }
  }

  // -------------------------------------------------------------------
  // Both pointers default to this site.
  // -------------------------------------------------------------------

  public function testBothOriginsShipPointingAtThisSite(): void {
    $defaults = Yaml::parseFile(PackageManifest::root() . '/modules/scolta_ui/config/install/scolta_ui.settings.yml');
    $sentinel = '<local>';

    foreach (self::NEW_KEYS as $key) {
      $this->assertSame(
        $sentinel,
        $defaults[$key] ?? NULL,
        "{$key} must ship as {$sentinel}: a default install builds and serves its own index and answers its own AI, exactly as the single module did"
      );
    }

    $origin = file_get_contents(PackageManifest::root() . '/modules/scolta_ui/src/Service/IndexOrigin.php');
    $this->assertStringContainsString(
      "public const LOCAL = '{$sentinel}';",
      $origin,
      'The sentinel in config and the sentinel in code must be the same string'
    );
  }

}
