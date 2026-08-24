<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Url;
use Drupal\scolta_ui\Access\AiAccessInterface;
use Drupal\scolta_ui\Service\AssetDeployer;
use Drupal\scolta\Service\IndexLocator;
use Drupal\scolta_ui\Service\ScoltaAiService;
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

  /**
   * Neither private nor readonly, both deliberately.
   *
   * BlockBase brings in DependencySerializationTrait, and unserializing a
   * plugin reassigns its service properties. That reassignment cannot reach a
   * private property and cannot write a readonly one at all — a readonly
   * property would raise "Cannot modify readonly property" the first time a
   * serialized block is restored. See https://www.drupal.org/node/3110266.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ScoltaAiService $aiService,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ConfigFactoryInterface $configFactory,
    protected LanguageManagerInterface $languageManager,
    protected AccountInterface $currentUser,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected IndexLocator $indexLocator,
    protected AiAccessInterface $aiAccess,
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
      $container->get('scolta.index_locator'),
      $container->get('scolta.ai_access'),
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
    // Resolve the WASM glue JS for client-side scoring. AssetDeployer copies
    // it (with the .wasm binary beside it) into the public files directory.
    $wasmPath = $this->fileUrlGenerator->generateString(AssetDeployer::DIRECTORY . '/wasm/scolta_core.js');

    $currentLanguage = $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
      ->getId();

    // Whether the browser is told the two AI features exist: the site offers
    // the feature AND this visitor may use it. The config half is unchanged —
    // it is what toJsScoringConfig() already emitted. The access half is new,
    // and asking the same service the endpoints ask means a visitor is never
    // handed a UI that fires a request the route will refuse: a visitor
    // without 'use scolta ai' used to get the full AI search and a pair of
    // 403s that scolta.js swallows into a console warning. Follow-ups are
    // only offered inside the overview, so they need no flag of their own.
    $expandAccess = $this->aiAccess->access($this->currentUser, AiAccessInterface::FEATURE_EXPAND);
    $summarizeAccess = $this->aiAccess->access($this->currentUser, AiAccessInterface::FEATURE_SUMMARIZE);

    $scoring = $config->toJsScoringConfig();
    $scoring['AI_EXPAND_QUERY'] = $scoring['AI_EXPAND_QUERY'] && $expandAccess->isAllowed();
    $scoring['AI_SUMMARIZE'] = $scoring['AI_SUMMARIZE'] && $summarizeAccess->isAllowed();

    $scoltaSettings = [
      'scoring' => $scoring,
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
      // deployed js/scolta.js, so the setting must work against any scolta-php
      // in the supported range, including one predating the property. An
      // unrecognized value clamps to 'eager', as the bundle does.
      'facetMode' => in_array($drupalConfig->get('facet_mode'), ['eager', 'deferred', 'disabled'], TRUE)
        ? $drupalConfig->get('facet_mode')
        : 'eager',
      'container' => '#scolta-search',
      'allowedLinkDomains' => [],
      'disclaimer' => '',
      'currentLanguage' => $currentLanguage,
      // Search as you type. Ten top-level keys the deployed bundle reads off
      // the instance config, taken from Drupal config rather than from
      // ScoltaConfig so the deployed bundle's suggestions work against any
      // scolta-php in the supported ^1.0 range: the SAYT implementation lives
      // entirely in js/scolta.js, deployed from the installed scolta-php.
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

    $build = [
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

    // The two flags are an access answer now, so this block is cached on
    // whatever that answer was read from. The shipped rule adds the
    // user.permissions context; a decorator that varies per user adds its own,
    // and without this the first visitor's answer would be served to the next.
    $cacheability = CacheableMetadata::createFromRenderArray($build);
    $cacheability->addCacheableDependency($expandAccess);
    $cacheability->addCacheableDependency($summarizeAccess);
    $cacheability->applyTo($build);

    return $build;
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
