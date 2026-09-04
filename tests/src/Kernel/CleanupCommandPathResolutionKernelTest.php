<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Commands\ScoltaCommands;
use Drush\Log\DrushLoggerManager;
use Psr\Log\AbstractLogger;

/**
 * `scolta:cleanup` refuses to run when it cannot resolve its output directory.
 *
 * The command resolves pagefind.output_dir through the stream wrapper manager,
 * and that resolution can fail — a private:// directory on a site with no
 * private file system is the everyday case. It used to hand the unresolved URI
 * to RetiredIndexTrash anyway and die on scolta-php's `Stream wrappers are not
 * allowed in file paths`, which names neither the setting nor its value.
 *
 * Drush is not involved: the command object is built from the same container
 * services drush.services.yml passes it and called directly, so this needs a
 * real container and a writable directory and nothing more. That directory is
 * a real temp path rather than public://, because KernelTestBase mounts
 * public:// on vfsStream and scolta-php's FilesystemDriver rejects a path with
 * a scheme in it outright.
 *
 * @group scolta
 */
class CleanupCommandPathResolutionKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta'];

  /**
   * A real filesystem directory standing in for the published index location.
   *
   * @var string
   */
  private string $outputDir;

  /**
   * Collects what the command logged.
   *
   * @var \Psr\Log\AbstractLogger
   */
  private AbstractLogger $recorder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta']);

    $this->outputDir = sys_get_temp_dir() . '/scolta-cleanup-test-' . uniqid();
    mkdir($this->outputDir, 0755, TRUE);
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->outputDir)
      ->save();

    $this->recorder = new class() extends AbstractLogger {

      /**
       * Every record logged, as "level: message".
       *
       * @var array
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, $message, array $context = []): void {
        $this->records[] = $level . ': ' . $message;
      }

    };
  }

  /**
   * The command object, wired the way drush.services.yml wires it.
   */
  private function commands(): ScoltaCommands {
    $commands = new ScoltaCommands(
      \Drupal::service('entity_type.manager'),
      \Drupal::service('config.factory'),
      \Drupal::service('http_client'),
      \Drupal::service('state'),
      \Drupal::service('cache.default'),
      \Drupal::service('scolta.ai_service'),
      \Drupal::service('stream_wrapper_manager'),
      \Drupal::service('scolta.content_gatherer'),
      \Drupal::service('file_system'),
      \Drupal::service('cache_tags.invalidator'),
      \Drupal::service('scolta.index_locator'),
    );

    $logger = new DrushLoggerManager();
    $logger->add('test', $this->recorder);
    $commands->setLogger($logger);

    return $commands;
  }

  /**
   * An output dir that cannot be resolved fails the command, loudly.
   */
  public function testUnresolvableOutputDirFailsInsteadOfReportingSuccess(): void {
    // KernelTestBase configures no private file system, so private:// has no
    // real path to resolve to and resolvePath() hands back the URI it got.
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', 'private://scolta-pagefind')
      ->save();

    try {
      $this->commands()->cleanup();
      $this->fail('cleanup() must not return normally when the output dir cannot be resolved.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('private://scolta-pagefind', $e->getMessage());
    }

    $this->assertSame([], $this->recorder->records,
      'A command that cannot resolve its output dir must not report an outcome it never reached.');
  }

  /**
   * A resolvable output dir still sweeps its trash.
   */
  public function testResolvableOutputDirStillDeletesTrash(): void {
    $trashDir = $this->outputDir . '/.scolta-trash-crashed';
    mkdir($trashDir . '/fragment', 0755, TRUE);
    file_put_contents($trashDir . '/fragment/stale.pf_fragment', 'x');

    $this->commands()->cleanup();

    $this->assertDirectoryDoesNotExist($trashDir);
  }

}
