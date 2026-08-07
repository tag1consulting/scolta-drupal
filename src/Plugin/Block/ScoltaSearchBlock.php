<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Url;
use Drupal\scolta\Service\IndexLocator;
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
    private readonly LanguageManagerInterface $languageManager,
    private readonly AccountInterface $currentUser,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly IndexLocator $indexLocator,
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
      $container->get('language_manager'),
      $container->get('current_user'),
      $container->get('stream_wrapper_manager'),
      $container->get('extension.list.module'),
      $container->get('scolta.index_locator'),
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
        $resolvedDir = $this->streamWrapperManager
          ->getViaUri($outputDir)->realpath() ?: $outputDir;
      }
      catch (\Exception $e) {
        // Fall through with unresolved URI.
      }
    }
    $indexExists = $this->indexLocator->exists($resolvedDir);

    if (!$indexExists) {
      // Output differs by the 'administer scolta' permission, so the render
      // cache must vary on permissions.
      $cache = [
        'tags' => ['scolta_search_index'],
        'contexts' => ['user.permissions'],
      ];
      if ($this->currentUser->hasPermission('administer scolta')) {
        $notice = $this->t(
          '<p><strong>Scolta:</strong> Search index has not been built yet.</p><p><a href=":url">Build now &rarr;</a> or run <code>drush scolta:build</code></p>',
          [':url' => Url::fromRoute('scolta.settings')->toString()]
        );
        return [
          '#markup' => '<div class="messages messages--warning">' . $notice . '</div>',
          '#cache' => $cache,
        ];
      }
      // Hide search block for non-admins when index is missing.
      return ['#cache' => $cache];
    }

    $config = $this->aiService->getConfig();

    $pagefindPath = $this->resolvePagefindUrl($outputDir);

    // Build the window.scolta configuration for the JS frontend.
    // Resolve the WASM glue JS path for client-side scoring.
    $modulePath = $this->moduleExtensionList->getPath('scolta');
    $wasmPath = base_path() . $modulePath . '/js/wasm/scolta_core.js';

    $currentLanguage = $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

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
      'filterFieldDescriptions' => $config->filterFieldDescriptions,
      'hideEmptyFacets' => $config->hideEmptyFacets,
      // Taken from Drupal config rather than from ScoltaConfig, for the same
      // reason the SAYT keys below are: the behaviour lives entirely in the
      // js/scolta.js this module vendors and ships, so the setting must work
      // against any scolta-php in the supported range, including one predating
      // the property. An unrecognized value clamps to 'eager', as the bundle
      // does.
      'facetMode' => in_array($drupalConfig->get('facet_mode'), ['eager', 'deferred', 'disabled'], TRUE)
        ? $drupalConfig->get('facet_mode')
        : 'eager',
      'container' => '#scolta-search',
      'allowedLinkDomains' => [],
      'disclaimer' => '',
      'currentLanguage' => $currentLanguage,
      // Search as you type. Ten top-level keys the committed bundle reads off
      // the instance config, taken from Drupal config rather than from
      // ScoltaConfig so the shipped bundle's suggestions work against any
      // scolta-php in the supported ^1.0 range: the SAYT implementation lives
      // entirely in js/scolta.js, which this module vendors and ships itself.
      // Defaults repeat the install defaults so a site that never ran the
      // update hook still gets the documented behavior.
      'saytEnabled' => (bool) ($drupalConfig->get('sayt_enabled') ?? TRUE),
      'saytMinChars' => (int) ($drupalConfig->get('sayt_min_chars') ?? 2),
      'saytDebounceMs' => (int) ($drupalConfig->get('sayt_debounce_ms') ?? 150),
      'saytMaxSuggestions' => (int) ($drupalConfig->get('sayt_max_suggestions') ?? 6),
      'saytRecentSearches' => (bool) ($drupalConfig->get('sayt_recent_searches') ?? TRUE),
      'saytMaxRecent' => (int) ($drupalConfig->get('sayt_max_recent') ?? 3),
      'saytExpand' => (bool) ($drupalConfig->get('sayt_expand') ?? TRUE),
      'saytExpandPerMinute' => (int) ($drupalConfig->get('sayt_expand_per_minute') ?? 6),
      'saytExpansionDelayMs' => (int) ($drupalConfig->get('sayt_expansion_delay_ms') ?? 500),
      // An unrecognized action clamps to 'navigate', as the bundle does.
      'saytSuggestionAction' => $drupalConfig->get('sayt_suggestion_action') === 'search' ? 'search' : 'navigate',
    ];

    $markup = '<div id="scolta-search"></div>';
    if ($config->showAttribution) {
      $markup .= '<p class="scolta-attribution">' . $this->t('Powered by Scolta') . '</p>';
    }

    return [
      '#markup' => $markup,
      '#attached' => [
        'library' => [
          'scolta/search',
          'scolta/drupal_bridge',
        ],
        'drupalSettings' => [
          'scolta' => $scoltaSettings,
        ],
      ],
      '#cache' => [
        // config:system.site covers the site-name fallback in drupalSettings;
        // the language context covers currentLanguage.
        'tags' => ['config:scolta.settings', 'config:system.site', 'scolta_search_index'],
        'contexts' => ['languages:language_content'],
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
