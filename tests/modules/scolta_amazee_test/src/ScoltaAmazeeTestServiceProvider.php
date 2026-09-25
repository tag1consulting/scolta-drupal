<?php

declare(strict_types=1);

namespace Drupal\scolta_amazee_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Points the Amazee.ai client at the offline fake instead of the network.
 */
class ScoltaAmazeeTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $container->getDefinition('scolta.amazee_client')
      ->replaceArgument(1, new Reference('scolta_amazee_test.http_client'));
  }

}
