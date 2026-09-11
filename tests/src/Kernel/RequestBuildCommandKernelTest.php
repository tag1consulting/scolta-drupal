<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Commands\ScoltaCommands;
use Drush\Log\DrushLoggerManager;
use Symfony\Component\Console\Output\NullOutput;

/**
 * `drush scolta:request-build` queues one full rebuild, and only one.
 *
 * @group scolta
 */
class RequestBuildCommandKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'search_api', 'scolta'];

  /**
   * A second request while one is waiting adds nothing.
   */
  public function testOneWaitingRequestIsEnough(): void {
    $queue = \Drupal::queue('scolta_rebuild');
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
      $this->container->get('scolta.index_build_runner'),
      $this->container->get('queue'),
    );
    $commands->setLogger(new DrushLoggerManager());
    $commands->setOutput(new NullOutput());

    $commands->requestBuild();
    $commands->requestBuild();
    $this->assertSame(1, $queue->numberOfItems());

    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    $this->assertSame(['type' => 'request-build'], $item->data, 'A bare full-rebuild request, which the worker treats as untargeted');
  }

}
