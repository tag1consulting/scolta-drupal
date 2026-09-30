<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\Block;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Drupal\scolta\Access\AiAccessInterface;
use Drupal\scolta\Service\AssetDeployer;

/**
 * Provides the Scolta chat block.
 *
 * A launcher that opens a conversation grounded in the site's pages. It
 * emits the same window.scolta settings as the search block (the chat ranks
 * pages with the search bundle) plus a `chat` block, and attaches
 * scolta/chat. Renders nothing while the chat is off, the index is not built
 * or the visitor may not use it.
 *
 * @Block(
 *   id = "scolta_chat",
 *   admin_label = @Translation("Scolta Chat"),
 *   category = @Translation("Search")
 * )
 *
 * @since 2.0.0
 * @stability experimental
 */
class ScoltaChatBlock extends ScoltaSearchBlock {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->aiService->getConfig();
    $chatAccess = $this->aiAccess->access($this->currentUser, AiAccessInterface::FEATURE_CHAT);
    $build = [
      '#cache' => [
        'tags' => ['config:scolta.settings', 'config:system.site', 'scolta_search_index'],
        // Signed in visitors get the CSRF token route; anonymous ones do not.
        'contexts' => ['languages:language_content', 'user.roles:authenticated'],
      ],
    ];
    $cacheability = CacheableMetadata::createFromRenderArray($build)->addCacheableDependency($chatAccess);

    if ($config->chatEnabled && $chatAccess->isAllowed() && $this->indexNotBuilt() === NULL) {
      [$settings, $access] = $this->browserSettings();
      foreach ($access as $result) {
        $cacheability->addCacheableDependency($result);
      }

      $chat = $config->toBrowserConfig()['chat'];
      $chat['deepChatPath'] = $this->fileUrlGenerator->generateString(AssetDeployer::DIRECTORY . '/vendor/deep-chat/deepChat.bundle.js');
      $chat['endpoints'] = [
        'plan' => Url::fromRoute('scolta.chat_plan')->toString(),
        'turn' => Url::fromRoute('scolta.chat_turn')->toString(),
        'fold' => Url::fromRoute('scolta.chat_fold')->toString(),
        'thread' => Url::fromRoute('scolta.chat_thread')->toString(),
      ];
      if ($this->currentUser->isAuthenticated()) {
        $chat['csrf'] = [
          'header' => 'X-CSRF-Token',
          'tokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
        ];
      }
      $settings['chat'] = $chat;

      // The widget adds its launcher to the page body; this element keeps
      // the block from being empty, which would drop its attachments.
      $build['#markup'] = '<div class="scolta-chat-mount" data-scolta-chat-ignore></div>';
      $build['#attached'] = [
        'library' => ['scolta/chat'],
        'drupalSettings' => ['scolta' => $settings],
      ];
    }

    $cacheability->applyTo($build);
    return $build;
  }

}
