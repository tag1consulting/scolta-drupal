<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
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
 * The invariant the wiring depends on — that put() is what spares an entry
 * from pruning — is executed for real against scolta-php's TimestampManifest.
 * The gather()-side wiring cannot be executed without a Drupal bootstrap and
 * is covered functionally elsewhere.
 */
class ForceBuildManifestPrimingTest extends TestCase {

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
        'A build that records nothing must be shown to empty the manifest — '
        . 'this is the failure mode the manifest wiring exists to prevent.'
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
