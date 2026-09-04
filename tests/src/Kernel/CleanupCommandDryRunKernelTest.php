<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Commands\ScoltaCommands;
use Drush\Log\DrushLoggerManager;
use Psr\Log\AbstractLogger;

/**
 * `scolta:cleanup --dry-run` changes nothing on disk.
 *
 * A `.scolta-old` left by an interrupted swap is retired into trash before the
 * command decides what to delete, and retiring renames the directory — so a
 * dry run, whose own option text promises "without deleting anything", moved a
 * directory every time it ran. The rename is now held back and the directory
 * is reported as pending instead.
 *
 * Drush is not involved: the command object is built from the same container
 * services drush.services.yml passes it and called directly. The output dir is
 * a real temp path rather than public://, because KernelTestBase mounts
 * public:// on vfsStream and scolta-php's FilesystemDriver rejects a path with
 * a scheme in it outright.
 *
 * @group scolta
 */
class CleanupCommandDryRunKernelTest extends KernelTestBase {

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

    $this->outputDir = sys_get_temp_dir() . '/scolta-dry-run-test-' . uniqid();
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
   * Creates a `.scolta-old` corpse with one fragment inside it.
   */
  private function makeOldDir(): string {
    $oldDir = $this->outputDir . '/.scolta-old';
    mkdir($oldDir . '/fragment', 0755, TRUE);
    file_put_contents($oldDir . '/fragment/stale.pf_fragment', 'x');
    return $oldDir;
  }

  /**
   * Every `.scolta-trash-*` directory currently under the output dir.
   */
  private function trashDirs(): array {
    return glob($this->outputDir . '/.scolta-trash-*') ?: [];
  }

  /**
   * A dry run leaves `.scolta-old` where it found it, and says so.
   */
  public function testDryRunDoesNotRetireTheOldDirectory(): void {
    $oldDir = $this->makeOldDir();

    $this->commands()->cleanup(['dry-run' => TRUE]);

    $this->assertFileExists($oldDir . '/fragment/stale.pf_fragment',
      '--dry-run must not rename .scolta-old into trash.');
    $this->assertSame([], $this->trashDirs(),
      '--dry-run must not create a trash directory.');
    $this->assertStringContainsString($oldDir, implode("\n", $this->recorder->records),
      'A directory a real run would delete must appear in the dry run listing.');
  }

  /**
   * A real run still retires `.scolta-old` and deletes it.
   */
  public function testRealRunRetiresAndDeletesTheOldDirectory(): void {
    $oldDir = $this->makeOldDir();

    $this->commands()->cleanup();

    $this->assertDirectoryDoesNotExist($oldDir);
    $this->assertSame([], $this->trashDirs());
  }

}
