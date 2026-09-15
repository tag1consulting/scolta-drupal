<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Commands\ScoltaCommands;
use Drupal\user\Entity\User;
use Drush\Log\DrushLoggerManager;
use Symfony\Component\Console\Output\NullOutput;

/**
 * `scolta:inspect` reads back the fragment the index holds for an entity.
 *
 * Fragment files are named by a content hash, so the command finds an entity's
 * page by decoding fragments and matching their URL. Two things can go wrong
 * quietly: the gzip + "pagefind_dcd" envelope can be mis-stripped, leaving
 * nothing decodable, and matching /user/1 loosely drags in /user/12. Both are
 * asserted here.
 *
 * The output dir is a real temp path rather than public://, because
 * KernelTestBase mounts public:// on vfsStream — see
 * CleanupCommandDryRunKernelTest.
 *
 * @group scolta
 */
class InspectCommandKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta'];

  /**
   * A real filesystem directory standing in for the published index location.
   *
   * @var string
   */
  private string $outputDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta']);
    $this->installEntitySchema('user');

    $this->outputDir = sys_get_temp_dir() . '/scolta-inspect-test-' . uniqid();
    mkdir($this->outputDir . '/pagefind/fragment', 0755, TRUE);
    file_put_contents($this->outputDir . '/pagefind/pagefind.js', '// stub');
    $this->config('scolta.settings')
      ->set('pagefind.output_dir', $this->outputDir)
      ->save();
  }

  /**
   * Write one fragment file the way PagefindFormatWriter writes it.
   */
  private function writeFragment(string $name, string $url, string $content): void {
    $json = json_encode([
      'url' => $url,
      'content' => $content,
      'word_count' => str_word_count($content),
      'filters' => ['subject' => ['Math']],
      'meta' => ['title' => $content],
      'anchors' => [],
    ], JSON_UNESCAPED_SLASHES);
    file_put_contents(
      $this->outputDir . '/pagefind/fragment/' . $name . '.pf_fragment',
      gzencode('pagefind_dcd' . $json, 9)
    );
  }

  /**
   * The command object, wired the way drush.services.yml wires it.
   */
  private function commands(): ScoltaCommands {
    $commands = new ScoltaCommands(
      $this->container->get('config.factory'),
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
      $this->container->get('entity_type.manager'),
      $this->container->get('scolta.reindexer'),
    );
    $commands->setLogger(new DrushLoggerManager());
    $commands->setOutput(new NullOutput());
    return $commands;
  }

  /**
   * The entity's own fragment comes back, and nothing adjacent to it.
   */
  public function testEntityReturnsItsOwnFragment(): void {
    $user = User::create(['name' => 'indexed']);
    $user->save();
    $url = $user->toUrl()->toString();

    $this->writeFragment('aaa', $url, 'the indexed profile');
    $this->writeFragment('bbb', $url . '2', 'a different profile');
    $this->writeFragment('ccc', '/lesson/photosynthesis', 'a lesson');

    $result = $this->commands()->inspect('user', (string) $user->id())->getArrayCopy();

    $this->assertSame(['aaa.pf_fragment'], array_keys($result));
    $fragment = $result['aaa.pf_fragment'];
    $this->assertSame($url, $fragment['url']);
    $this->assertSame('the indexed profile', $fragment['content']);
    $this->assertSame(['subject' => ['Math']], $fragment['filters']);
  }

  /**
   * A translation under a language prefix is found alongside the original.
   */
  public function testTranslationUnderLanguagePrefixIsFound(): void {
    $user = User::create(['name' => 'indexed']);
    $user->save();
    $url = $user->toUrl()->toString();

    $this->writeFragment('aaa', $url, 'the profile');
    $this->writeFragment('bbb', '/es' . $url, 'el perfil');

    $result = $this->commands()->inspect('user', (string) $user->id())->getArrayCopy();

    $this->assertEqualsCanonicalizing(['aaa.pf_fragment', 'bbb.pf_fragment'], array_keys($result));
  }

  /**
   * Without a built index the command says so rather than reporting nothing.
   */
  public function testMissingIndexIsAnError(): void {
    unlink($this->outputDir . '/pagefind/pagefind.js');
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No built index');
    $this->commands()->inspect('user', '1');
  }

}
