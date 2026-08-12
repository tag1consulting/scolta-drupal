<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\TimestampManifest;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * A --force build must leave the timestamp manifest primed, not empty.
 *
 * --force is a rule about what a build READS: reload every entity, trust
 * nothing cached. It was implemented as withholding the manifest entirely, so
 * the build also stopped WRITING to it — and the orchestrator's pruneAndSave()
 * at the end then found nothing marked seen and deleted every entry. The forced
 * build itself looked fine. The cost landed on the next one, which found an
 * empty manifest, treated the whole corpus as changed, and ran a second full
 * gather: a ~55m cold build where a ~10m warm one was expected, with nothing in
 * either build's output to explain it.
 *
 * The same call sites carry the exporter's manifest wiring, which is what lets
 * bodies too short to index be recorded rather than re-gathered every build, so
 * both are asserted here together.
 *
 * gather() cannot be executed without a Drupal bootstrap, which this suite does
 * not have, so the gate structure is asserted by inspection in line with the
 * rest of the suite. The invariant the wiring depends on — that put() is what
 * spares an entry from pruning — is executed for real against scolta-php's
 * TimestampManifest.
 */
class ForceBuildManifestPrimingTest extends TestCase {

  private string $moduleRoot;
  private string $gatherer;
  private string $commands;
  private string $worker;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->gatherer = file_get_contents($this->moduleRoot . '/src/Service/ScoltaContentGatherer.php');
    $this->commands = file_get_contents($this->moduleRoot . '/src/Commands/ScoltaCommands.php');
    $this->worker = file_get_contents($this->moduleRoot . '/src/Plugin/QueueWorker/ScoltaRebuildWorker.php');
  }

  // -------------------------------------------------------------------
  // The invariant the wiring rests on — executed, not inspected
  // -------------------------------------------------------------------

  /**
   * put() marks the entity seen, and seen is what survives the prune.
   *
   * This is why "withhold the manifest under --force" and "empty the manifest
   * under --force" turned out to be the same statement.
   */
  public function testPutIsWhatSparesAnEntryFromPruning(): void {
    $dir = sys_get_temp_dir() . '/scolta-force-priming-' . uniqid('', TRUE);
    mkdir($dir, 0755, TRUE);

    try {
      $manifest = new TimestampManifest($dir, new FilesystemDriver());
      $manifest->put('recorded', 1_700_000_000, [$this->itemRecord('recorded')]);
      $manifest->pruneAndSave();

      // A build that loads the entity but records nothing leaves it unseen.
      $second = new TimestampManifest($dir, new FilesystemDriver());
      $this->assertNotNull($second->get('recorded'), 'Setup failed: the entry was never stored.');
      $second->pruneAndSave();

      $third = new TimestampManifest($dir, new FilesystemDriver());
      $this->assertNull(
        $third->get('recorded'),
        'A build that records nothing must be shown to empty the manifest — if this '
        . 'ever stops being true the gates below are guarding nothing.'
      );

      // The same build, recording what it loaded, keeps it.
      $fourth = new TimestampManifest($dir, new FilesystemDriver());
      $fourth->put('recorded', 1_700_000_000, [$this->itemRecord('recorded')]);
      $fourth->pruneAndSave();
      $fifth = new TimestampManifest($dir, new FilesystemDriver());
      $fifth->markSeen('recorded');
      $fifth->pruneAndSave();

      $this->assertNotNull(
        (new TimestampManifest($dir, new FilesystemDriver()))->get('recorded'),
        'An entry the build recorded must survive the prune.'
      );
    }
    finally {
      $this->removeDir($dir);
    }
  }

  // -------------------------------------------------------------------
  // The gates: --force suppresses the skip, never the record
  // -------------------------------------------------------------------

  public function testCommandPassesTheManifestUnderForce(): void {
    $this->assertStringNotContainsString(
      '$force ? NULL : $orchestrator->getTimestampManifest()',
      $this->commands,
      'scolta:build must hand the gatherer the manifest under --force too. Withholding '
      . 'it stops the build recording anything, and the end-of-build prune then empties '
      . 'the manifest, so the NEXT build re-gathers the whole corpus.'
    );
    $this->assertStringContainsString(
      '$tsManifest = $orchestrator->getTimestampManifest();',
      $this->commands,
      'scolta:build must take the orchestrator\'s manifest unconditionally.'
    );
  }

  public function testTheSkipDecisionIsTheOnlyThingForceGates(): void {
    $gates = substr_count($this->gatherer, '!$force');

    $this->assertSame(
      2,
      $gates,
      'ScoltaContentGatherer must gate exactly two things on !$force — the skip '
      . 'decision in gather() and the one in gatherByIds(). A third gate means '
      . '--force has started suppressing a write again.'
    );
  }

  public function testRecordingIsNotGatedOnForce(): void {
    $this->assertStringNotContainsString(
      '$manifest !== NULL && !$force && !empty($itemsForManifest)',
      $this->gatherer,
      'put() must run whatever --force says: it is also what marks the entity seen.'
    );
    $this->assertSame(
      2,
      substr_count($this->gatherer, '$manifest !== NULL && !empty($itemsForManifest)'),
      'Both gather() and gatherByIds() must record every entity they load.'
    );
  }

  /**
   * The stored timestamp and the timestamp a later build compares it against
   * have to come from the same place.
   *
   * getEntityTimestamps() is the source of the value that gets stored, not just
   * an input to the skip decision. Gated on !$force it returns nothing under
   * --force, $entityTs falls through to buildContentItems(), and that reads the
   * changed time off the first translation carrying a body — a different value
   * on any multilingual entity. The entry a --force build wrote would then
   * never match on the next build, and the corpus would re-gather forever: the
   * bug this change is fixing, reintroduced by the obvious version of the fix.
   */
  public function testTimestampsAreQueriedWheneverThereIsAManifestToWrite(): void {
    $this->assertSame(
      2,
      substr_count($this->gatherer, '$timestamps = $manifest !== NULL'),
      'Both walks must query changed timestamps whenever a manifest is present, '
      . 'independently of --force.'
    );
    $this->assertStringNotContainsString(
      '($manifest !== NULL && !$force)
        ? $this->getEntityTimestamps',
      $this->gatherer,
      'The timestamp query must not be gated on --force.'
    );
  }

  // -------------------------------------------------------------------
  // The exporter carries the known-empty record
  // -------------------------------------------------------------------

  public function testBothBuildPathsHandTheManifestToTheExporter(): void {
    // scolta:build picks its gather source first (full walk or --entity-ids),
    // so the shape is filterItems($source, $tsManifest) with both sources
    // coming from the gatherer and both carrying the manifest.
    $this->assertMatchesRegularExpression(
      '/filterItems\(\s*\$source,\s*\$tsManifest\s*\)/s',
      $this->commands,
      'scolta:build must pass the manifest to filterItems() — the exporter is where a '
      . 'body too short to index is dropped, and the only place that can record it.'
    );
    $this->assertMatchesRegularExpression(
      '/\$source = \$this->contentGatherer->gather\([^;]*\$tsManifest,\s*\$force\)/s',
      $this->commands,
      'The full-walk source must come from the gatherer with the manifest.'
    );
    $this->assertMatchesRegularExpression(
      '/\$source = \$this->contentGatherer->gatherByIds\([^;]*\$tsManifest,\s*\$force\)/s',
      $this->commands,
      'The --entity-ids source must come from the gatherer with the manifest.'
    );
    $this->assertMatchesRegularExpression(
      '/filterItems\(\s*\$this->contentGatherer->gather\([^;]*?\),\s*\$tsManifest\s*\)/s',
      $this->worker,
      'The queue worker must pass the manifest to filterItems() as well.'
    );
  }

  public function testTheGathererAndTheExporterShareOneManifestInstance(): void {
    // Two instances would each hold half the state and prune the other's away.
    $this->assertSame(
      1,
      substr_count($this->worker, '$orchestrator->getTimestampManifest()'),
      'The queue worker must resolve the manifest once and pass the same instance to '
      . 'both the gatherer and the exporter.'
    );
    $this->assertSame(
      1,
      substr_count($this->commands, '$orchestrator->getTimestampManifest();'),
      'scolta:build must resolve the manifest once and share the instance.'
    );
  }

  /**
   * Turns itself on the moment scolta-php ships the recorder.
   *
   * The wiring above is inert against a scolta-php whose filterItems() takes no
   * manifest: the extra argument is accepted and ignored. This is what makes
   * the wiring real, and it is deliberately a skip rather than a failure so the
   * branch is honest about its upstream dependency instead of red for a reason
   * that is not a defect.
   */
  public function testExporterAcceptsTheManifestOnceUpstreamShipsIt(): void {
    $params = (new \ReflectionMethod(ContentExporter::class, 'filterItems'))->getParameters();

    if (count($params) < 2) {
      $this->markTestSkipped(
        'The installed scolta-php has no manifest parameter on ContentExporter::filterItems(); '
        . 'the known-empty recorder is not on main yet. The wiring in this module is already '
        . 'correct and inert until it is.'
      );
    }

    $this->assertSame('manifest', $params[1]->getName());
    $type = $params[1]->getType();
    $this->assertNotNull($type);
    $this->assertStringContainsString('TimestampManifest', (string) $type);
  }

  // -------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------

  /**
   * A manifest item record in the shape the gatherer writes.
   */
  private function itemRecord(string $id): array {
    return [
      'hash' => 'hash-' . $id,
      'id' => $id,
      'url' => '/' . $id,
      'date' => '2026-01-01',
      'siteName' => 'Test',
      'language' => 'en',
      'filters' => [],
      'sortable' => [],
      'metadata' => [],
    ];
  }

  private function removeDir(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    foreach (scandir($dir) as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $path = $dir . '/' . $entry;
      is_dir($path) ? $this->removeDir($path) : unlink($path);
    }
    rmdir($dir);
  }

}
