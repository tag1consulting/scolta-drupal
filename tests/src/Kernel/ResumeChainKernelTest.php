<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\scolta\Commands\ScoltaCommands;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drush\Log\DrushLoggerManager;
use Symfony\Component\Console\Output\NullOutput;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\ResumeChainRunner;

/**
 * An operator's `--resume` drives the chain the same way a fresh build does.
 *
 * Observed on Share My Lesson staging (2026-09-10, 124,053 entities): every
 * hand-launched `drush scolta:build --resume` indexed one segment and stopped
 * with "Re-run `drush scolta:build --resume` to continue". The command took
 * any `--resume` for a segment spawned by its own chain, which must report to
 * its parent rather than chain again; the parent is now the only process
 * that sets ResumeChainRunner::SEGMENT_ENV, and the flag alone no longer
 * means that.
 *
 * @group scolta
 */
class ResumeChainKernelTest extends KernelTestBase {

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
   * The segment command the chain tried to run, if it got that far.
   */
  public ?string $spawned = NULL;

  /**
   * The environment the chain set for that segment.
   */
  public array $spawnedEnv = [];

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

    // scolta-php's FilesystemDriver rejects the vfs:// URIs KernelTestBase
    // mounts public:// on; see ScopedBuildKernelTest.
    $this->indexRoot = sys_get_temp_dir() . '/scolta-resume-chain-' . uniqid();
    mkdir($this->indexRoot, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->indexRoot . '/output')
      ->set('pagefind.build_dir', $this->indexRoot . '/build')
      ->save();

    $this->createContentType(['type' => 'article']);
    for ($i = 1; $i <= 6; $i++) {
      Node::create([
        'type' => 'article',
        'title' => 'Article number ' . $i,
        'status' => 1,
        'body' => [
          'value' => '<p>' . str_repeat("Indexable prose about article {$i}. ", 30) . '</p>',
          'format' => 'plain_text',
        ],
      ])->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(ResumeChainRunner::SEGMENT_ENV);
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
   * Run scolta:build with a build that yields on memory after its first chunk.
   *
   * The orchestrator's memory-pressure probe is the only way to reach the
   * yield path without a corpus that fills the heap, and the segment the
   * chain spawns is captured instead of run: a child drush here would run
   * against the wrong database.
   *
   * @return string
   *   The message the command threw.
   */
  protected function runYieldingBuild(bool $resume): string {
    $test = $this;
    $commands = new class(
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
      $this->container->get('scolta.index_build_runner'),
      $this->container->get('queue'),
    ) extends ScoltaCommands {

      /**
       * The test that owns this command double.
       */
      public ResumeChainKernelTest $test;

      /**
       * {@inheritdoc}
       */
      protected function orchestrator(string $stateDir, string $outputDir, string $language): IndexBuildOrchestrator {
        return new IndexBuildOrchestrator($stateDir, $outputDir, NULL, $language, memoryPressureProbe: static fn(): bool => TRUE);
      }

      /**
       * {@inheritdoc}
       */
      protected function runForeground(string $cmd, array $env): int {
        $this->test->spawned = $cmd;
        $this->test->spawnedEnv = $env;
        throw new \RuntimeException('segment captured');
      }

    };
    $commands->test = $test;
    $commands->setLogger(new DrushLoggerManager());
    $commands->setOutput(new NullOutput());

    $this->spawned = NULL;
    try {
      $commands->build([
        'entity-type' => 'node',
        'bundle' => '',
        'entity-ids' => '',
        'output-dir' => $this->indexRoot . '/export',
        'skip-pagefind' => FALSE,
        'indexer' => 'php',
        'force' => FALSE,
        'memory-budget' => NULL,
        'chunk-size' => 2,
        'resume' => $resume,
        'restart' => FALSE,
        'reset-ledger' => FALSE,
      ]);
    }
    catch (\RuntimeException $e) {
      return $e->getMessage();
    }
    $this->fail('A build that yields on memory must not return as if it had finished.');
  }

  /**
   * A hand-launched --resume chains, marking the segment it spawns.
   */
  public function testAnOperatorResumeChainsLikeAFreshBuild(): void {
    $this->assertSame('segment captured', $this->runYieldingBuild(resume: FALSE), 'A fresh build that yields chains.');
    $this->assertSame('segment captured', $this->runYieldingBuild(resume: TRUE), 'An operator --resume that yields chains too.');
    $this->assertSame([ResumeChainRunner::SEGMENT_ENV => '1'], $this->spawnedEnv);
    $this->assertStringContainsString(' scolta:build --indexer=php --resume', (string) $this->spawned);
  }

  /**
   * The segment the chain spawned yields to its parent instead of chaining.
   */
  public function testASpawnedSegmentReportsToItsParent(): void {
    $this->runYieldingBuild(resume: FALSE);

    putenv(ResumeChainRunner::SEGMENT_ENV . '=1');
    $message = $this->runYieldingBuild(resume: TRUE);
    $this->assertStringContainsString('Re-run `drush scolta:build --resume` to continue', $message);
    $this->assertNull($this->spawned, 'A segment must not spawn a segment.');
  }

}
