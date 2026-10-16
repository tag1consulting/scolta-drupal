<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Swaps the AI service for the scripted one; its arguments stay as they are.
 */
class ScoltaChatTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $container->getDefinition('scolta.ai_service')->setClass(FakeChatAiService::class);
  }

}
