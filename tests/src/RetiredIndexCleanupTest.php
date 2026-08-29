<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Commands\ScoltaCommands;
use Drupal\scolta\Service\IndexLocator;
use Drupal\scolta\Service\ScoltaAiService;
use Drupal\scolta\Service\ScoltaContentGatherer;
use Drush\Log\DrushLoggerManager;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Behavioral tests for retired-index cleanup: drush scolta:cleanup and cron.
 *
 * Publishing a new index parks the previous one in a `.scolta-trash-*`
 * directory and sweeps it after publishing (scolta-php's RetiredIndexTrash).
 * The command and the cron hook are the backstops for builds that died
 * before their own sweep and for the batch-UI path, which never sweeps.
 * These tests run both against real temporary directories and assert what
 * was deleted and what survived.
 */
class RetiredIndexCleanupTest extends TestCase {

  private string $outputDir;

  /**
   * PSR logger recording every entry by level (including drush 'success').
   */
  private AbstractLogger $recorder;

  protected function setUp(): void {
    $this->outputDir = sys_get_temp_dir() . '/scolta-cleanup-test-' . uniqid('', TRUE);
    mkdir($this->outputDir . '/pagefind', 0755, TRUE);
    file_put_contents($this->outputDir . '/pagefind/pagefind-entry.json', '{}');

    $this->recorder = new class extends AbstractLogger {
      /** @var array<string, list<string>> */
      public array $records = [];

      public function log($level, string|\Stringable $message, array $context = []): void {
        $interpolated = (string) $message;
        foreach ($context as $key => $value) {
          if (is_scalar($value)) {
            $interpolated = str_replace('{' . $key . '}', (string) $value, $interpolated);
          }
        }
        $this->records[(string) $level][] = $interpolated;
      }

    };
  }

  protected function tearDown(): void {
    if (is_dir($this->outputDir)) {
      $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->outputDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
      rmdir($this->outputDir);
    }
    \Drupal::unsetContainer();
  }

  private function makeTrashDir(string $suffix = 'crashed'): string {
    $dir = $this->outputDir . '/.scolta-trash-' . $suffix;
    mkdir($dir . '/fragment', 0755, TRUE);
    file_put_contents($dir . '/fragment/stale.pf_fragment', 'x');
    return $dir;
  }

  private function trashDirs(): array {
    return glob($this->outputDir . '/.scolta-trash-*') ?: [];
  }

  /**
   * A config factory whose scolta.settings answers from the given map.
   */
  private function configFactory(array $settings): ConfigFactoryInterface {
    $config = new class ($settings) {

      public function __construct(private readonly array $settings) {}

      public function get(string $key): mixed {
        return $this->settings[$key] ?? NULL;
      }

    };
    $factory = $this->createStub(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

  private function makeCommands(array $settings): ScoltaCommands {
    $commands = new ScoltaCommands(
      $this->createStub(EntityTypeManagerInterface::class),
      $this->configFactory($settings),
      $this->createStub(ClientInterface::class),
      $this->createStub(StateInterface::class),
      $this->createStub(CacheBackendInterface::class),
      $this->createStub(ScoltaAiService::class),
      $this->createStub(StreamWrapperManagerInterface::class),
      $this->createStub(ScoltaContentGatherer::class),
      $this->createStub(FileSystemInterface::class),
      $this->createStub(CacheTagsInvalidatorInterface::class),
      $this->createStub(IndexLocator::class),
    );
    $manager = new DrushLoggerManager();
    $manager->add('test', $this->recorder);
    $commands->setLogger($manager);
    return $commands;
  }

  // -------------------------------------------------------------------
  // drush scolta:cleanup
  // -------------------------------------------------------------------

  public function testCleanupDeletesTrashAndStaleScoltaOldButNotTheLiveIndex(): void {
    $this->makeTrashDir();
    // A corpse from a swap that died between retiring the old index and
    // publishing the new one: the command must retire and delete it too.
    mkdir($this->outputDir . '/.scolta-old', 0755, TRUE);
    file_put_contents($this->outputDir . '/.scolta-old/stale.pf_fragment', 'x');

    $this->makeCommands(['pagefind.output_dir' => $this->outputDir])
      ->cleanup(['dry-run' => FALSE]);

    $this->assertSame([], $this->trashDirs());
    $this->assertDirectoryDoesNotExist($this->outputDir . '/.scolta-old');
    $this->assertFileExists($this->outputDir . '/pagefind/pagefind-entry.json');
    $this->assertNotEmpty($this->recorder->records['success'] ?? []);
  }

  public function testCleanupDryRunListsButDeletesNothing(): void {
    $trashDir = $this->makeTrashDir();

    $this->makeCommands(['pagefind.output_dir' => $this->outputDir])
      ->cleanup(['dry-run' => TRUE]);

    $this->assertFileExists($trashDir . '/fragment/stale.pf_fragment');
    $listing = implode("\n", $this->recorder->records['notice'] ?? []);
    $this->assertStringContainsString($trashDir, $listing);
  }

  public function testCleanupNormalizesAPagefindSuffixedOutputDir(): void {
    // Misconfigured output_dir pointing at the published directory itself:
    // trash lives beside pagefind/, not inside it, and the command must
    // normalize the same way the build does or it would sweep nothing.
    $this->makeTrashDir();

    $this->makeCommands(['pagefind.output_dir' => $this->outputDir . '/pagefind'])
      ->cleanup(['dry-run' => FALSE]);

    $this->assertSame([], $this->trashDirs());
    $this->assertFileExists($this->outputDir . '/pagefind/pagefind-entry.json');
  }

  // -------------------------------------------------------------------
  // hook_cron backstop
  // -------------------------------------------------------------------

  private function setContainer(array $settings): void {
    $loggerFactory = $this->createStub(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn(new NullLogger());

    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory($settings));
    $container->set('logger.factory', $loggerFactory);
    $container->set('stream_wrapper_manager', $this->createStub(StreamWrapperManagerInterface::class));
    \Drupal::setContainer($container);

    require_once dirname(__DIR__, 2) . '/scolta.module';
  }

  public function testCronSweepsLeftoverTrash(): void {
    $this->makeTrashDir();
    $this->setContainer(['pagefind.output_dir' => $this->outputDir]);

    scolta_cron();

    $this->assertSame([], $this->trashDirs());
    $this->assertFileExists($this->outputDir . '/pagefind/pagefind-entry.json');
  }

  public function testCronCleanupIsDisabledByAZeroBudget(): void {
    $trashDir = $this->makeTrashDir();
    $this->setContainer([
      'pagefind.output_dir' => $this->outputDir,
      'cleanup.cron_seconds' => 0,
    ]);

    scolta_cron();

    $this->assertFileExists($trashDir . '/fragment/stale.pf_fragment');
  }

}
