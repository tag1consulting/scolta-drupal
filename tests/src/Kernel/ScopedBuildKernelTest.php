<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\scolta\Commands\ScoltaCommands;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drush\Log\DrushLoggerManager;
use Symfony\Component\Console\Output\NullOutput;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * A scoped build must not delete the pages it was never asked to look at.
 *
 * Observed on production (sml, 2026-09-02): `drush scolta:build
 * --entity-type=node --bundle=tntl` gathered 1,518 pages, and the orchestrator
 * then released every one of the ~14,600 ledger rows the scoped gather had not
 * yielded — releaseStaleRows() reads "this build did not yield it" as "it was
 * deleted at the source", which is only true of a build that walked the whole
 * corpus. The merge padded the freed ordinals with tombstones and the swap
 * published the result: 16,166 fragments, 1,518 of them live. The rest of the
 * site had left the index.
 *
 * The scope now travels with the build. This drives the real command, because
 * the defect was in what the command failed to tell the orchestrator rather
 * than in the orchestrator alone; the orchestrator's own half is covered by
 * scolta-php's PartialScopeBuildTest.
 *
 * @group scolta
 */
class ScopedBuildKernelTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'scolta', 'search_api', 'node', 'filter', 'field', 'text', 'dblog',
  ];

  /**
   * Real filesystem root for the index, outside vfsStream.
   */
  protected string $indexRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['scolta', 'field', 'node', 'filter']);

    // scolta-php's FilesystemDriver rejects stream-wrapper URIs, and
    // KernelTestBase mounts public:// on vfsStream whose realpath() is itself
    // a vfs:// URI. Point the index at a real temp directory, exactly as
    // IncrementalQueueUpdateKernelTest does.
    $this->indexRoot = sys_get_temp_dir() . '/scolta-scoped-build-' . uniqid();
    mkdir($this->indexRoot, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->indexRoot . '/output')
      ->set('pagefind.build_dir', $this->indexRoot . '/build')
      ->save();

    $this->createContentType(['type' => 'article']);
    $this->createContentType(['type' => 'page']);

    // Two bundles, so a build scoped to one of them leaves the other out.
    foreach (['article' => 6, 'page' => 5] as $type => $count) {
      for ($i = 1; $i <= $count; $i++) {
        Node::create([
          'type' => $type,
          'title' => ucfirst($type) . ' number ' . $i,
          'status' => 1,
          'body' => [
            'value' => '<p>' . str_repeat("Indexable prose about {$type} {$i}. ", 30) . '</p>',
            'format' => 'plain_text',
          ],
        ])->save();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->indexRoot)) {
      $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->indexRoot, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
      }
      rmdir($this->indexRoot);
    }
    parent::tearDown();
  }

  /**
   * The real command, wired for a process that has no Drush runtime.
   */
  protected function commands(): ScoltaCommands {
    // Constructed from the arguments drush.services.yml lists, because Drush's
    // service provider does not run in a kernel test and the tagged service is
    // therefore not in the container. A drift between the two is caught by
    // StructuralIntegrityTest's service-argument check, not here.
    $commands = new ScoltaCommands(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('http_client'),
      $this->container->get('state'),
      $this->container->get('cache.default'),
      $this->container->get('scolta.ai_service'),
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('scolta.content_gatherer'),
      $this->container->get('file_system'),
      $this->container->get('cache_tags.invalidator'),
      $this->container->get('scolta.index_locator'),
    );
    $commands->setLogger(new DrushLoggerManager());
    $commands->setOutput(new NullOutput());

    return $commands;
  }

  /**
   * Run scolta:build with the PHP indexer against the test's index directory.
   *
   * @param array<string, mixed> $overrides
   *   Option values to override on top of the command's own defaults.
   */
  protected function runBuild(array $overrides = []): void {
    $this->commands()->build($overrides + [
      'entity-type' => 'node',
      'bundle' => '',
      'entity-ids' => '',
      'output-dir' => $this->indexRoot . '/export',
      'docroot' => 'docroot',
      'skip-pagefind' => FALSE,
      'indexer' => 'php',
      'force' => FALSE,
      'memory-budget' => NULL,
      'chunk-size' => NULL,
      'resume' => FALSE,
      'restart' => FALSE,
    ]);
  }

  /**
   * Run a build that is expected to refuse, and return the refusal message.
   *
   * PHPUnit's AssertionFailedError extends RuntimeException, so a fail() call
   * inside the try block would be caught by the catch looking for the refusal
   * and the test would pass whatever happened. The assertion goes outside.
   *
   * @param array<string, mixed> $overrides
   *   Option values to override, including whichever scopes the build.
   *
   * @return string
   *   The refusal message the command threw.
   */
  protected function runBuildExpectingRefusal(array $overrides): string {
    $message = NULL;
    try {
      $this->runBuild($overrides);
    }
    catch (\RuntimeException $e) {
      $message = $e->getMessage();
    }

    $this->assertNotNull($message, 'A scoped build that cannot republish the rest of the index must throw.');

    return $message;
  }

  /**
   * The page-table ledger the build under test wrote.
   *
   * @return \Tag1\Scolta\Index\PageTableLedger
   *   Ledger read from the build directory.
   */
  protected function ledger(): PageTableLedger {
    return new PageTableLedger($this->indexRoot . '/build', new FilesystemDriver());
  }

  /**
   * Fingerprint every file in the published index.
   *
   * @return array<string, string>
   *   Relative path => sha256 of the bytes, so two indexes compare by content.
   */
  protected function publishedIndex(): array {
    $base = $this->indexRoot . '/output/pagefind';
    if (!is_dir($base)) {
      return [];
    }
    $manifest = [];
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($items as $file) {
      if ($file->isFile()) {
        $manifest[substr($file->getPathname(), strlen($base) + 1)]
          = hash('sha256', (string) file_get_contents($file->getPathname()));
      }
    }
    ksort($manifest);

    return $manifest;
  }

  /**
   * The --bundle regression: pages of the other bundle stay live.
   */
  public function testABundleScopedBuildLeavesTheOtherBundleLive(): void {
    $this->runBuild();

    $this->assertSame(11, $this->ledger()->liveCount(), 'The full build must index both bundles.');
    $published = $this->publishedIndex();
    $this->assertNotSame([], $published, 'The full build must publish an index.');

    $message = $this->runBuildExpectingRefusal(['bundle' => 'article']);
    $this->assertStringContainsString('This build was scoped with --bundle=article', $message);
    $this->assertStringContainsString('scoped build refused', $message);

    $ledger = $this->ledger();
    $this->assertSame(
      11,
      $ledger->liveCount(),
      'The five page nodes were outside the scope; a scoped build must not release them.',
    );
    $this->assertSame([], $ledger->tombstones(), 'Nothing outside the scope may be tombstoned.');
    $this->assertSame(
      $published,
      $this->publishedIndex(),
      'The refusal must leave the previously published index serving, byte for byte.',
    );
  }

  /**
   * The --entity-ids scoping gets the same refusal as --bundle.
   */
  public function testAnEntityIdScopedBuildLeavesTheRestLive(): void {
    $this->runBuild();
    $published = $this->publishedIndex();

    $nids = array_slice(array_keys(
      $this->container->get('entity_type.manager')->getStorage('node')->loadMultiple()
    ), 0, 2);

    $message = $this->runBuildExpectingRefusal(['entity-ids' => implode(',', $nids)]);
    $this->assertStringContainsString('This build was scoped with --entity-ids', $message);

    $this->assertSame(11, $this->ledger()->liveCount());
    $this->assertSame($published, $this->publishedIndex());
  }

  /**
   * A bundle-only site keeps building, because its scope covers the ledger.
   *
   * Such a site passes --bundle on every build, so nothing is ever out of
   * scope and the guard must not stand in its way.
   */
  public function testABundleScopedBuildIsFineWhenTheIndexHoldsOnlyThatBundle(): void {
    $this->runBuild(['bundle' => 'article']);

    $this->assertSame(6, $this->ledger()->liveCount());
    $first = $this->publishedIndex();
    $this->assertNotSame([], $first, 'The first scoped build has nothing to protect and must publish.');

    // And again, still scoped, on an index that now holds exactly that scope.
    $this->runBuild(['bundle' => 'article']);

    $this->assertSame(6, $this->ledger()->liveCount());
    $this->assertSame($first, $this->publishedIndex());
  }

}
