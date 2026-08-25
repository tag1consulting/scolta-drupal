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
   * Parsed from the source rather than called: including scolta.install to
   * call _scolta_update_10006_frontend_keys() would pull the whole file, and
   * its hooks, into a suite that never boots Drupal — and the point is to
   * check what the shipped file says anyway.
   */
  private function migratedKeys(): array {
    $source = file_get_contents(PackageManifest::root() . '/scolta.install');

    $start = strpos($source, 'function _scolta_update_10006_frontend_keys(): array {');
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
  // The code has to agree with the partition, not just the schemas.
  // -------------------------------------------------------------------

  /**
   * No backend file reads a key the migration moved out from under it.
   *
   * The partition above is a statement about two YAML files. This is the
   * statement about the code, and it is the one that broke: site_name and
   * ai_languages moved to scolta_ui.settings while four build-time callers
   * went on reading them off scolta.settings. After scolta_update_10006()
   * clears them from the source object every one of those reads returns
   * NULL, so an upgraded site silently indexed under the Drupal site name
   * and in English regardless of what it had configured — with nothing
   * failing, because a build with a wrong site name still succeeds.
   *
   * scolta.install is out of scope: its update hooks are historical records
   * that read the pre-split object on purpose.
   */
  public function testTheBackendReadsNoKeyTheSplitMovedAway(): void {
    $migrated = $this->migratedKeys();

    foreach ($this->backendSourceFiles() as $relative => $source) {
      foreach ($this->keysReadFromBackendSettings($source) as $key) {
        $top = explode('.', $key)[0];
        $this->assertNotContains(
          $top,
          $migrated,
          "{$relative} reads '{$key}' from scolta.settings, but scolta_update_10006() moves it to scolta_ui.settings — on every upgraded site that read returns NULL"
        );
      }
    }
  }

  /**
   * The deliberate cross-object reads are all still made.
   *
   * Four keys are read across the split on purpose, and each one is a bug
   * the moment it stops being read rather than the moment it starts. The
   * dimension names are the ones that were actually missing: they stayed in
   * scolta.settings, correctly, but ScoltaAiService never read them, so
   * ScoltaConfig::sortableFields and ::filterFields reached
   * AiEndpointHandler empty and it quietly stopped detecting sort intent and
   * emitting filter suggestions on every site that had configured any.
   *
   * @param string $file
   *   The source file expected to make the read.
   * @param string $key
   *   The config key it must read out of scolta.settings.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('crossObjectReadProvider')]
  public function testTheDeliberateCrossObjectReadsAreStillMade(string $file, string $key): void {
    $source = file_get_contents(PackageManifest::root() . '/' . $file);
    $this->assertNotFalse($source, "{$file} must exist");

    $this->assertContains(
      $key,
      $this->keysReadFromBackendSettings($source),
      "{$file} must read '{$key}' from scolta.settings: it is build-time state the frontend needs, and losing the read fails silently rather than loudly"
    );
  }

  public static function crossObjectReadProvider(): array {
    return [
      // The vocabulary the AI expands sort and filter intent against.
      'sortable dimension names' => ['modules/scolta_ui/src/Service/ScoltaAiService.php', 'sortable_fields'],
      'filter dimension names' => ['modules/scolta_ui/src/Service/ScoltaAiService.php', 'filter_fields'],
      // Where the local index is, for the block and for /health.
      'index output directory' => ['modules/scolta_ui/src/Service/IndexOrigin.php', 'pagefind.output_dir'],
      // The binary row on the health report.
      'pagefind binary' => ['modules/scolta_ui/src/Controller/HealthController.php', 'pagefind.binary'],
    ];
  }

  /**
   * The backend's own PHP, excluding the update hooks.
   *
   * @return array<string, string>
   *   File contents, keyed by path relative to the repository root.
   */
  private function backendSourceFiles(): array {
    $files = [];

    foreach (PackageManifest::sourceFiles() + PackageManifest::proceduralFiles() as $relative => $source) {
      if ($relative === 'scolta.install' || str_starts_with($relative, 'modules/')) {
        continue;
      }
      $files[$relative] = $source;
    }

    return $files;
  }

  /**
   * The config keys a file reads out of the scolta.settings object.
   *
   * Two forms, because both are in use: the inline chain
   * `$this->config('scolta.settings')->get('x')`, and the far commoner
   * `$config = ...get('scolta.settings')` followed by `$config->get('x')`
   * somewhere below — including inside a helper the object was passed to,
   * which is why the binding is tracked per file rather than per function.
   *
   * @param string $source
   *   PHP source.
   *
   * @return string[]
   *   Distinct config keys.
   */
  private function keysReadFromBackendSettings(string $source): array {
    $keys = [];

    preg_match_all(
      "/(?:config|get)\(\s*'scolta\.settings'\s*\)\s*->\s*get\(\s*'([a-z0-9_.]+)'/i",
      $source,
      $inline
    );
    foreach ($inline[1] as $key) {
      $keys[$key] = TRUE;
    }

    preg_match_all(
      "/\\\$(\w+)\s*=\s*[^;\n]*(?:config|get)\(\s*'scolta\.settings'\s*\)/i",
      $source,
      $bindings
    );
    foreach (array_unique($bindings[1]) as $variable) {
      preg_match_all("/\\\$" . preg_quote($variable, '/') . "->get\(\s*'([a-z0-9_.]+)'/i", $source, $reads);
      foreach ($reads[1] as $key) {
        $keys[$key] = TRUE;
      }
    }

    return array_keys($keys);
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


  /**
   * Only the migration itself may write a migrated key to scolta.settings.
   *
   * Update hooks run in number order, and three that predate the split --
   * 10002, 10003 and 10004 -- back-fill or migrate settings the split moved
   * to scolta_ui.settings. Each of them went on writing them to
   * scolta.settings, whose schema no longer declares a single one, so on any
   * site upgrading from before those hooks `drush updb` wrote keys with no
   * schema behind them and the functional suite failed outright under config
   * schema checking. The partition test that guards the rest of the codebase
   * excludes scolta.install wholesale, because the migration legitimately
   * touches both objects -- which is exactly why this needs saying separately.
   *
   * The rule: a function in scolta.install that opens scolta.settings for
   * writing may not also name a key the split moved away. The one exception
   * is the migration, whose entire job is to hold both objects at once.
   */
  public function testNoUpdateHookWritesAMigratedKeyToTheBackendObject(): void {
    $migrated = $this->migratedKeys();
    $source = file_get_contents(PackageManifest::root() . '/scolta.install');
    $this->assertNotFalse($source);

    $offenders = [];
    foreach ($this->installFunctions($source) as $name => $body) {
      if ($name === '_scolta_apply_module_split') {
        continue;
      }
      if (!str_contains($body, "getEditable('scolta.settings')")) {
        continue;
      }
      foreach ($migrated as $key) {
        if (str_contains($body, "'" . $key . "'")) {
          $offenders[] = $name . ' writes ' . $key;
        }
      }
    }

    $this->assertSame(
      [],
      $offenders,
      'These write a key the split moved into the object the split emptied: '
      . implode(', ', $offenders)
    );
  }

  /**
   * Every function in scolta.install, keyed by name, with its body.
   *
   * Brace-counted from each function's opening brace, which is enough for a
   * procedural file with no closures or nested functions.
   *
   * @param string $source
   *   The contents of scolta.install.
   *
   * @return array<string, string>
   *   Function name => body source.
   */
  private function installFunctions(string $source): array {
    preg_match_all('/^function ([a-z0-9_]+)\s*\(/mi', $source, $matches, PREG_OFFSET_CAPTURE);

    $functions = [];
    foreach ($matches[1] as $i => [$name, $_]) {
      $start = strpos($source, '{', $matches[0][$i][1]);
      $depth = 0;
      for ($pos = $start; $pos < strlen($source); $pos++) {
        if ($source[$pos] === '{') {
          $depth++;
        }
        elseif ($source[$pos] === '}') {
          $depth--;
          if ($depth === 0) {
            break;
          }
        }
      }
      $functions[$name] = substr($source, $start, $pos - $start);
    }

    $this->assertNotEmpty($functions, 'scolta.install must define functions');

    return $functions;
  }

}
