<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Scolta AI-powered search block.
 *
 * Renders a search container, attaches the scolta/search library, and
 * injects window.scolta configuration via drupalSettings. Drop this
 * block on any page via Block Layout to get a fully working search UI.
 *
 * @Block(
 *   id = "scolta_search",
 *   admin_label = @Translation("Scolta Search"),
 *   category = @Translation("Search")
 * )
 */
class ScoltaSearchBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ScoltaAiService $aiService,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('scolta.ai_service'),
      $container->get('file_url_generator'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Resolve the Pagefind output directory to a web-accessible URL.
    $drupalConfig = $this->configFactory->get('scolta.settings');
    $outputDir = $drupalConfig->get('pagefind.output_dir') ?? 'public://scolta-pagefind';

    // Check if index exists on the filesystem.
    $resolvedDir = $outputDir;
    if (str_contains($outputDir, '://')) {
      try {
        $swm = \Drupal::service('stream_wrapper_manager');
        $resolvedDir = $swm->getViaUri($outputDir)->realpath() ?: $outputDir;
      }
      catch (\Exception $e) {
        // Fall through with unresolved URI.
      }
    }
    $indexExists = file_exists($resolvedDir . '/pagefind/pagefind-entry.json');

    if (!$indexExists) {
      if (\Drupal::currentUser()->hasPermission('administer site configuration')) {
        return [
          '#markup' => '<div class="messages messages--warning">'
            . '<p><strong>Scolta:</strong> Search index has not been built yet.</p>'
            . '<p><a href="/admin/config/search/scolta">Build now &rarr;</a> or run <code>drush scolta:build</code></p>'
            . '</div>',
          '#cache' => ['tags' => ['scolta_search_index']],
        ];
      }
      // Hide search block for non-admins when index is missing.
      return ['#cache' => ['tags' => ['scolta_search_index']]];
    }

    $config = $this->aiService->getConfig();

    $pagefindPath = $this->resolvePagefindUrl($outputDir);

    // Build the window.scolta configuration for the JS frontend.
    // Resolve the WASM glue JS path for client-side scoring.
    $modulePath = \Drupal::service('extension.list.module')->getPath('scolta');
    $wasmPath = '/' . $modulePath . '/js/wasm/scolta_core.js';

    $scoltaSettings = [
      'scoring' => $config->toJsScoringConfig(),
      'endpoints' => [
        'expand' => Url::fromRoute('scolta.expand')->toString(),
        'summarize' => Url::fromRoute('scolta.summarize')->toString(),
        'followup' => Url::fromRoute('scolta.followup')->toString(),
      ],
      'pagefindPath' => $pagefindPath . '/pagefind/pagefind.js',
      'wasmPath' => $wasmPath,
      'siteName' => $config->siteName ?: $this->configFactory->get('system.site')->get('name'),
      'container' => '#scolta-search',
      'allowedLinkDomains' => [],
      'disclaimer' => '',
    ];

    return [
      '#markup' => '<div id="scolta-search"></div>',
      '#attached' => [
        'library' => [
          'scolta/search',
          'scolta/drupal_bridge',
        ],
        'drupalSettings' => [
          'scolta' => $scoltaSettings,
        ],
      ],
    ];
  }

  /**
   * Resolve a stream wrapper URI to a web-accessible URL path.
   *
   * @param string $uri
   *   A URI like 'public://scolta-pagefind' or an absolute path.
   *
   * @return string
   *   A web-accessible URL path (without trailing slash).
   */
  protected function resolvePagefindUrl(string $uri): string {
    if (str_contains($uri, '://')) {
      try {
        $url = $this->fileUrlGenerator->generateString($uri);
        return rtrim($url, '/');
      }
      catch (\Exception $e) {
        // Fall through to return the URI as-is.
      }
    }
    return rtrim($uri, '/');
  }

}
