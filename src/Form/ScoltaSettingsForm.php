<?php

declare(strict_types=1);

namespace Drupal\scolta\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Url;
use Drupal\scolta\Batch\ScoltaBatchOperations;
use Drupal\scolta\Service\PagefindBuilder;
use Drupal\scolta\Service\ScoltaAiService;
use Drupal\scolta\Service\ScoltaContentGatherer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Config\ApiKeySource;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Prompt\DefaultPrompts;

/**
 * Scolta configuration form.
 *
 * Provides a comprehensive settings interface organized into sections:
 * AI, Content, Scoring, Display, Cache, Custom Prompts, and Status.
 */
class ScoltaSettingsForm extends ConfigFormBase {

  /**
   * The default AI model shipped in config/install/scolta.settings.yml.
   *
   * Equal to AiClient::DEFAULT_MODEL, which ScoltaAiService::modelIsResolved()
   * treats as "no gateway model resolved yet" and scolta_update_10003() resets
   * ai_model to when it migrates a gateway alias out of it, so the literal
   * must stay in sync with the install config.
   */
  public const DEFAULT_AI_MODEL = 'claude-sonnet-4-5-20250929';

  /**
   * The Scolta AI service.
   *
   * @var \Drupal\scolta\Service\ScoltaAiService
   */
  protected ScoltaAiService $aiService;

  /**
   * The Pagefind builder service.
   *
   * @var \Drupal\scolta\Service\PagefindBuilder
   */
  protected PagefindBuilder $pagefindBuilder;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * The content gatherer service.
   *
   * @var \Drupal\scolta\Service\ScoltaContentGatherer
   */
  protected ScoltaContentGatherer $contentGatherer;

  /**
   * The managed Amazee.ai gateway credential store.
   *
   * @var \Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface
   */
  protected ConfigStorageInterface $amazeeConfigStorage;

  /**
   * Constructs a ScoltaSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\scolta\Service\ScoltaAiService $aiService
   *   The Scolta AI service.
   * @param \Drupal\scolta\Service\PagefindBuilder $pagefindBuilder
   *   The Pagefind builder service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param \Drupal\scolta\Service\ScoltaContentGatherer $contentGatherer
   *   The content gatherer service.
   * @param \Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface $amazeeConfigStorage
   *   The managed-gateway credential store, cleared when the operator selects
   *   a different AI provider.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    ScoltaAiService $aiService,
    PagefindBuilder $pagefindBuilder,
    StreamWrapperManagerInterface $streamWrapperManager,
    EntityTypeManagerInterface $entityTypeManager,
    StateInterface $state,
    FileSystemInterface $fileSystem,
    CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    ScoltaContentGatherer $contentGatherer,
    ConfigStorageInterface $amazeeConfigStorage,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
    $this->aiService = $aiService;
    $this->pagefindBuilder = $pagefindBuilder;
    $this->streamWrapperManager = $streamWrapperManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->state = $state;
    $this->fileSystem = $fileSystem;
    $this->cacheTagsInvalidator = $cacheTagsInvalidator;
    $this->contentGatherer = $contentGatherer;
    $this->amazeeConfigStorage = $amazeeConfigStorage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('scolta.ai_service'),
      $container->get('scolta.pagefind_builder'),
      $container->get('stream_wrapper_manager'),
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('file_system'),
      $container->get('cache_tags.invalidator'),
      $container->get('scolta.content_gatherer'),
      $container->get('scolta.amazee_config_storage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['scolta.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'scolta_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('scolta.settings');

    // ── AI Section ──
    $form['ai'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Configuration'),
      '#open' => TRUE,
    ];

    // The saved provider is the whole answer. It used to fall back to
    // detecting stored Amazee.ai credentials when nothing had been saved,
    // which is the auto-selection this opt-in rule removes: a connection
    // existing is not the operator choosing it, and the field would have
    // shown a provider that governs AI traffic without anyone selecting it.
    // A saved 'drupal_ai' is nothing special any more, for the same reason
    // (#125 kept it from being overridden; there is no longer an override).
    // No preselection. Scolta ships with no provider chosen, and an untouched
    // site must show the placeholder rather than a provider nobody picked: a
    // preselected 'anthropic' is indistinguishable, to the operator reading
    // the form, from a deliberate choice. A site that already saved a provider
    // keeps showing it — removing the default is going-forward only.
    $storedProvider = $config->get('ai_provider');
    $defaultProvider = ($storedProvider !== NULL && $storedProvider !== '')
      ? $storedProvider
      : '';

    $providerOptions = [
      'anthropic' => $this->t('Anthropic (Claude)'),
      'openai' => $this->t('OpenAI'),
      'amazee' => $this->t('Amazee.ai (managed gateway)'),
    ];
    if ($this->aiService->hasDrupalAiModule()) {
      $providerOptions['drupal_ai'] = $this->t('Drupal AI module');
    }

    $form['ai']['ai_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Provider'),
      '#options' => $providerOptions,
      // The placeholder is a real state, not a prompt to ignore: leaving it
      // selected keeps AI off, which is what an unconfigured site does.
      '#empty_option' => $this->t('- Select a provider -'),
      '#empty_value' => '',
      '#default_value' => $defaultProvider,
      '#description' => $this->t('No provider is selected by default. While none is selected, AI features are off and search works exactly as it does now.'),
    ];

    $amazee_url = Url::fromRoute('scolta.settings.amazee')->toString();
    $form['ai']['ai_provider_amazee_info'] = [
      '#type' => 'item',
      '#markup' => $this->t('Selecting Amazee.ai does not connect anything on its own. Save, then go to <a href="@url">Amazee.ai settings</a> and either try the free demo (no email, no account) or sign in to your amazee.ai account. Until you take one of those actions, AI stays off.', ['@url' => $amazee_url]),
      '#states' => [
        'visible' => [
          ':input[name="ai_provider"]' => ['value' => 'amazee'],
        ],
      ],
    ];

    // Selecting Amazee.ai does not by itself connect anything: the connection
    // is established in the Amazee.ai settings flow, by a deliberate click.
    // Say so, and link there, whenever the provider is selected with nothing
    // stored — otherwise the setting looks applied while AI stays off.
    if ($this->amazeeConfigStorage->load() === NULL) {
      $form['ai']['ai_provider_amazee_connect'] = [
        '#type' => 'item',
        '#markup' => '<strong>' . $this->t('No Amazee.ai connection yet. <a href="@url">Set up Amazee.ai</a> to finish enabling it.', ['@url' => $amazee_url]) . '</strong>',
        '#states' => [
          'visible' => [
            ':input[name="ai_provider"]' => ['value' => 'amazee'],
          ],
        ],
      ];
    }

    $form['ai']['ai_provider_drupal_ai_info'] = [
      '#type' => 'item',
      '#markup' => $this->t('Scolta will use the default AI provider and model configured in the <a href="@url">Drupal AI module</a>. Model, API key, and base URL fields below are managed by the Drupal AI module and are hidden when this provider is selected.', [
        '@url' => Url::fromUserInput('/admin/config/ai/providers')->toString(),
      ]),
      '#states' => [
        'visible' => [
          ':input[name="ai_provider"]' => ['value' => 'drupal_ai'],
        ],
      ],
    ];

    $form['ai']['ai_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AI Model'),
      '#default_value' => $config->get('ai_model') ?? self::DEFAULT_AI_MODEL,
      '#description' => $this->t('Model identifier for summarize and follow-up (e.g., claude-sonnet-4-5-20250929, gpt-4o).'),
      '#states' => [
        'invisible' => [
          ':input[name="ai_provider"]' => ['value' => 'drupal_ai'],
        ],
      ],
    ];

    $form['ai']['ai_expansion_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Expansion Model'),
      '#default_value' => $config->get('ai_expansion_model') ?? '',
      '#description' => $this->t('Optional model for query expansion only. Leave blank to use AI Model for all operations. Example: claude-haiku-4-5-20251001'),
      '#states' => [
        'invisible' => [
          ':input[name="ai_provider"]' => ['value' => 'drupal_ai'],
        ],
      ],
    ];

    $form['ai']['ai_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AI Base URL'),
      '#default_value' => $config->get('ai_base_url') ?? '',
      '#description' => $this->t('Override the default API URL. Leave blank to use provider defaults.'),
      '#states' => [
        'invisible' => [
          ':input[name="ai_provider"]' => ['value' => 'drupal_ai'],
        ],
      ],
    ];

    $form['ai']['ai_expand_query'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI query expansion'),
      '#default_value' => $config->get('ai_expand_query') ?? TRUE,
      '#description' => $this->t('Use AI to expand search queries into related terms.'),
    ];

    $form['ai']['ai_summarize'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI summarization'),
      '#default_value' => $config->get('ai_summarize') ?? TRUE,
      '#description' => $this->t('Use AI to generate summaries of search results.'),
    ];

    $form['ai']['ai_languages'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AI Languages'),
      '#default_value' => implode(', ', $config->get('ai_languages') ?? ['en']),
      '#description' => $this->t("Comma-separated language codes (e.g., en, es, fr). When multiple languages are configured, AI responses will match the language of the user's query."),
    ];

    $form['ai']['max_follow_ups'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum follow-up questions'),
      '#default_value' => $config->get('max_follow_ups') ?? 3,
      '#min' => 0,
      '#max' => 20,
      '#description' => $this->t('Maximum number of follow-up questions per search session.'),
    ];

    $apiKeyStatus = $this->buildApiKeyStatus();
    $apiKeyStatus['#states'] = [
      'invisible' => [
        ':input[name="ai_provider"]' => ['value' => 'drupal_ai'],
      ],
    ];
    $form['ai']['api_key_status'] = $apiKeyStatus;

    // ── Content Section ──
    $form['content'] = [
      '#type' => 'details',
      '#title' => $this->t('Content'),
      '#open' => TRUE,
    ];

    $form['content']['site_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site name'),
      '#default_value' => $config->get('site_name') ?? '',
      '#description' => $this->t('Used in AI prompts. Leave blank to use the Drupal site name.'),
    ];

    $form['content']['site_description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site description'),
      '#default_value' => $config->get('site_description') ?? 'website',
      '#maxlength' => 512,
      '#description' => $this->t('Brief description used in AI prompts (e.g., "corporate website", "health system websites").'),
    ];

    $form['content']['body_fields'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Body content fields'),
      '#default_value' => implode(', ', $config->get('body_fields') ?? []),
      '#description' => $this->t('Comma-separated entity fields searched for body text, in precedence order — the first one holding a value on a given translation is indexed. Content with none of these fields is skipped entirely, so add any bundle-specific field here (e.g. <code>body, field_body, field_content, field_recipe_instruction</code>). Leave empty to fall back to <code>body, field_body, field_content</code>.'),
    ];

    $form['content']['sortable_fields'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sortable fields'),
      '#default_value' => implode(', ', $config->get('sortable_fields') ?? []),
      '#description' => $this->t('Comma-separated list of fields available for sorting (e.g., "date, price"). When non-empty, the AI can detect sort intent and return a sort hint.'),
    ];

    $sortableDescRaw = $config->get('sortable_field_descriptions') ?? [];
    $sortableDescDisplay = '';
    foreach ($sortableDescRaw as $field => $desc) {
      $sortableDescDisplay .= "{$field}|{$desc}\n";
    }
    $form['content']['sortable_field_descriptions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sortable field descriptions'),
      '#default_value' => trim($sortableDescDisplay),
      '#rows' => 4,
      '#description' => $this->t('One <code>field_name|Description</code> per line. Descriptions help the AI map natural language to field names. Example: <code>word_count|Article length in words — higher means more comprehensive coverage</code>.'),
    ];

    $form['content']['filter_fields'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filter fields'),
      '#default_value' => implode(', ', $config->get('filter_fields') ?? []),
      '#description' => $this->t('Comma-separated list of filter dimension names (e.g., "topic, era, region"). Must match the filter names used in data-pagefind-filter attributes.'),
    ];

    $filterDescRaw = $config->get('filter_field_descriptions') ?? [];
    $filterDescDisplay = '';
    foreach ($filterDescRaw as $field => $desc) {
      $filterDescDisplay .= "{$field}|{$desc}\n";
    }
    $form['content']['filter_field_descriptions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Filter field descriptions'),
      '#default_value' => trim($filterDescDisplay),
      '#rows' => 4,
      '#description' => $this->t('One <code>dimension|Description</code> per line. Listing valid values helps the AI map user queries to filter values. Example: <code>topic|Subject area or domain. Values: Science, History, Biography, Geography, Arts</code>.'),
    ];

    $sortableMappingRaw = $config->get('field_mappings.sortable') ?? [];
    $sortableMappingDisplay = '';
    foreach ($sortableMappingRaw as $field => $dimension) {
      $sortableMappingDisplay .= "{$field}|{$dimension}\n";
    }
    $form['content']['field_mapping_sortable'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sortable field mappings'),
      '#default_value' => trim($sortableMappingDisplay),
      '#rows' => 4,
      '#description' => $this->t('Auto-map entity fields to sortable dimensions during indexing. One <code>entity_field_name|dimension_name</code> per line. Example: <code>field_word_count|word_count</code>. Supports entity reference fields (resolves to label), numeric fields, and text fields. The hook <code>hook_scolta_content_item_alter()</code> can still override these values.'),
    ];

    $filterMappingRaw = $config->get('field_mappings.filters') ?? [];
    $filterMappingDisplay = '';
    foreach ($filterMappingRaw as $field => $dimension) {
      $filterMappingDisplay .= "{$field}|{$dimension}\n";
    }
    $form['content']['field_mapping_filters'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Filter field mappings'),
      '#default_value' => trim($filterMappingDisplay),
      '#rows' => 4,
      '#description' => $this->t('Auto-map entity fields to filter dimensions during indexing. One <code>entity_field_name|dimension_name</code> per line. Example: <code>field_topics|topics</code>. Entity reference fields (e.g., taxonomy terms) resolve to the referenced entity label. Multi-value references are joined with commas.'),
    ];

    $form['content']['indexer'] = [
      '#type' => 'select',
      '#title' => $this->t('Indexer mode'),
      '#options' => [
        'auto' => $this->t('Auto (PHP indexer — recommended, works on all hosts)'),
        'php' => $this->t('PHP (pure-PHP, no binary needed)'),
        'binary' => $this->t('Binary (requires Pagefind CLI)'),
      ],
      '#default_value' => $config->get('indexer') ?? 'auto',
      '#description' => $this->t('How scolta:build creates the search index. Auto uses the PHP indexer, which works on all hosting environments and supports fast incremental re-indexing. Can be overridden with --indexer on the CLI.'),
    ];

    $memoryBudgetConfig = MemoryBudgetConfig::load([
      'profile'      => $config->get('memory_budget.profile') ?? 'conservative',
      'custom_bytes' => $config->get('memory_budget.custom_bytes'),
      'chunk_size'   => $config->get('memory_budget.chunk_size'),
    ]);
    $form['content']['memory_budget'] = MemoryBudgetSettingsFieldSet::build($memoryBudgetConfig);

    // ── Site Type Section ──
    $presets = ScoltaConfig::getPresets();
    $currentPreset = $config->get('preset') ?? 'none';

    $presetOptions = [];
    foreach ($presets as $key => $meta) {
      $presetOptions[$key] = $meta['label'];
    }

    $form['site_type'] = [
      '#type' => 'details',
      '#title' => $this->t('Site Type'),
      '#open' => TRUE,
    ];

    $form['site_type']['site_type_intro'] = [
      '#type' => 'markup',
      '#markup' => '<p class="description">' . $this->t('Presets adjust how Scolta ranks search results — how much weight goes to titles vs. page content, whether newer content ranks higher, and how broadly Scolta interprets what you searched for. The preset is a starting point, not a constraint: you can optionally open the Scoring section below to change any individual setting.') . '</p>',
    ];

    $form['site_type']['preset'] = [
      '#type' => 'select',
      '#title' => $this->t('What kind of site is this?'),
      '#options' => $presetOptions,
      '#default_value' => $currentPreset,
    ];

    foreach ($presets as $key => $meta) {
      $form['site_type']['preset_desc_' . $key] = [
        '#type' => 'markup',
        '#markup' => '<p class="description scolta-preset-description scolta-preset-description--' . $key . '"><strong>' . $meta['label'] . ':</strong> ' . $meta['description'] . '</p>',
      ];
    }

    // ── Scoring Section ──
    $presetLabel = isset($presets[$currentPreset]) ? $presets[$currentPreset]['label'] : '';
    $scoringDescription = ($currentPreset !== 'none' && $presetLabel !== '')
      ? (string) $this->t("These settings were populated by the @label preset. Change any value here and your change takes priority — the preset only fills in what you haven't touched.", ['@label' => $presetLabel])
      : (string) $this->t('Configure each scoring parameter individually.');

    $form['scoring'] = [
      '#type' => 'details',
      '#title' => $this->t('Scoring'),
      '#open' => FALSE,
      '#description' => $scoringDescription,
    ];

    $form['scoring']['title_match_boost'] = [
      '#type' => 'number',
      '#title' => $this->t('Title match boost'),
      '#default_value' => $config->get('scoring.title_match_boost') ?? 2.0,
      '#step' => 'any',
      '#min' => 0,
      '#description' => $this->t('Boost factor for title matches.'),
    ];

    $form['scoring']['title_all_terms_multiplier'] = [
      '#type' => 'number',
      '#title' => $this->t('Title all terms multiplier'),
      '#default_value' => $config->get('scoring.title_all_terms_multiplier') ?? 1.5,
      '#step' => 'any',
      '#min' => 0,
      '#description' => $this->t('Extra multiplier when all search terms appear in the title.'),
    ];

    $form['scoring']['content_match_boost'] = [
      '#type' => 'number',
      '#title' => $this->t('Content match boost'),
      '#default_value' => $config->get('scoring.content_match_boost') ?? 0.4,
      '#step' => 'any',
      '#min' => 0,
      '#description' => $this->t('Boost factor for content body matches.'),
    ];

    $form['scoring']['recency_boost_max'] = [
      '#type' => 'number',
      '#title' => $this->t('Recency boost maximum'),
      '#default_value' => $config->get('scoring.recency_boost_max') ?? 0.25,
      '#step' => 'any',
      '#min' => 0,
      '#description' => $this->t('Maximum boost for recent content.'),
    ];

    $form['scoring']['recency_half_life_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Recency half-life (days)'),
      '#default_value' => $config->get('scoring.recency_half_life_days') ?? 365,
      '#min' => 1,
      '#description' => $this->t('Number of days for recency boost to decay by half.'),
    ];

    $form['scoring']['recency_penalty_after_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Recency penalty after (days)'),
      '#default_value' => $config->get('scoring.recency_penalty_after_days') ?? 1825,
      '#min' => 0,
      '#description' => $this->t('Content older than this many days gets a penalty.'),
    ];

    $form['scoring']['recency_max_penalty'] = [
      '#type' => 'number',
      '#title' => $this->t('Recency maximum penalty'),
      '#default_value' => $config->get('scoring.recency_max_penalty') ?? 0.3,
      '#step' => 'any',
      '#min' => 0,
      '#description' => $this->t('Maximum recency penalty for old content.'),
    ];

    $form['scoring']['expand_primary_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Expanded term primary weight'),
      '#default_value' => $config->get('scoring.expand_primary_weight') ?? 0.5,
      '#step' => 'any',
      '#min' => 0,
      '#max' => 1,
      '#description' => $this->t('Weight given to the original query vs. expanded terms (0-1).'),
    ];

    $form['scoring']['cross_list_bonus'] = [
      '#type' => 'number',
      '#title' => $this->t('Cross-list agreement bonus'),
      '#default_value' => $config->get('scoring.cross_list_bonus') ?? 0.05,
      '#step' => 'any',
      '#min' => 0,
      '#max' => 1,
      '#description' => $this->t('Additive score bonus when a result appears in both primary and expanded result sets. Set to 0 to disable.'),
    ];

    $form['scoring']['expand_subword_max_frequency'] = [
      '#type' => 'number',
      '#title' => $this->t('Search breadth (advanced)'),
      '#default_value' => $config->get('scoring.expand_subword_max_frequency') ?? 0.05,
      '#step' => 'any',
      '#min' => 0,
      '#max' => 1,
      '#description' => $this->t("Advanced: how aggressively multi-word searches broaden. Higher returns more results but can pull in loosely-related matches; lower keeps results tight. Most sites should pick a Site Type preset above instead of changing this by hand. Default: 0.05 (the Recipe &amp; Content Catalog preset raises it to 0.10). Set to 0 to disable sub-word expansion; values at or above 1 search every sub-word."),
    ];

    $form['scoring']['specificity_weighting'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Specificity-weighted ranking'),
      '#default_value' => $config->get('scoring.specificity_weighting') ?? TRUE,
      '#description' => $this->t('Weight each partial match by how rare its term is in the corpus, so a match on a rare intent-bearing term outranks a match on a ubiquitous one. This is what stops a common word, typed or leaked from an expansion phrase, from flooding the head of the result list. Uncheck to restore flat sub-query weighting.'),
    ];

    $form['scoring']['specificity_floor'] = [
      '#type' => 'number',
      '#title' => $this->t('Specificity floor'),
      '#default_value' => $config->get('scoring.specificity_floor') ?? 0.15,
      '#step' => 'any',
      '#min' => 0,
      '#max' => 1,
      '#description' => $this->t('Floor for the specificity weight of a ubiquitous term. A term appearing in nearly every document is damped to this multiplier rather than to zero, so it still contributes to recall while ranking far below rare terms. Lower is more aggressive damping. Default: 0.15.'),
    ];

    $form['scoring']['specificity_strong_match'] = [
      '#type' => 'number',
      '#title' => $this->t('Specificity strong-match threshold'),
      '#default_value' => $config->get('scoring.specificity_strong_match') ?? 0.55,
      '#step' => 'any',
      '#min' => 0,
      '#max' => 1,
      '#description' => $this->t('Specificity at or above which a matched term counts as a strong, on-intent hit. When a term this specific matched, the partial-match banner and the AI summary stop framing the result set as a failure and attribute any gap to the search rather than the collection. Default: 0.55.'),
    ];

    $form['scoring']['specificity_cooccurrence'] = [
      '#type' => 'number',
      '#title' => $this->t('Co-occurrence agreement bonus'),
      '#default_value' => $config->get('scoring.specificity_cooccurrence') ?? 0.9,
      '#step' => 'any',
      '#min' => 0,
      '#max' => 5,
      '#description' => $this->t('Scales the bonus a result earns for agreeing with several query and expansion terms at once, rather than matching one term strongly. A page that is on-topic across the whole query usually answers it better than one that spikes on a single rare word. Default: 0.9. Set to 0 to score each result purely by its single best-matching sub-query.'),
    ];

    $form['scoring']['specificity_agreement_gate'] = [
      '#type' => 'number',
      '#title' => $this->t('Co-occurrence agreement gate'),
      '#default_value' => $config->get('scoring.specificity_agreement_gate') ?? 0.45,
      '#step' => 'any',
      '#min' => 0,
      '#max' => 1,
      '#description' => $this->t('Specificity a term must clear before it counts toward the agreement bonus. Terms below the gate are too common for their presence to be evidence of topical agreement, so they are excluded rather than inflating the count. Default: 0.45.'),
    ];

    $form['scoring']['specificity_agreement_decay'] = [
      '#type' => 'number',
      '#title' => $this->t('Co-occurrence agreement decay'),
      '#default_value' => $config->get('scoring.specificity_agreement_decay') ?? 1.0,
      '#step' => 'any',
      '#min' => 0,
      '#max' => 5,
      '#description' => $this->t('Geometric factor applied to each successive agreeing term, so the second is worth this fraction of the first and so on. Values below 1 make the bonus saturate, which keeps a long page matching many mid-specificity terms from overtaking a focused page matching a genuinely rare one. Default: 1.0 (every agreeing term weighted equally).'),
    ];

    $form['scoring']['expansion_combine_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Expansion combine mode'),
      '#default_value' => $config->get('scoring.expansion_combine_mode') ?? 'relevance_union',
      '#options' => [
        'relevance_union' => $this->t('Relevance union (default)'),
        'round_robin' => $this->t('Round-robin across sub-queries'),
      ],
      '#description' => $this->t('How the per-sub-query result sets of a multi-term query expansion are combined into the AI summary candidate set. <em>Relevance union</em> keeps the historical behavior. <em>Round-robin</em> deals the top candidates from each expansion sub-query so the summarizer sees breadth across distinct sub-topics. The visible results list stays relevance-sorted either way.'),
    ];

    $form['scoring']['exact_title_match_boost'] = [
      '#type' => 'number',
      '#title' => $this->t('Exact title match boost'),
      '#default_value' => $config->get('scoring.exact_title_match_boost') ?? 5.0,
      '#step' => 'any',
      '#min' => 0,
      '#description' => $this->t('Multiplicative boost when a result title exactly matches the search query (case-insensitive). Set to 0 to disable.'),
    ];

    $form['scoring']['phrase_adjacent_multiplier'] = [
      '#type' => 'number',
      '#title' => $this->t('Phrase adjacent multiplier'),
      '#default_value' => $config->get('scoring.phrase_adjacent_multiplier') ?? 2.5,
      '#step' => 'any',
      '#min' => 0,
      '#description' => $this->t('Boost multiplier when query terms appear adjacent in content.'),
    ];

    $form['scoring']['phrase_near_multiplier'] = [
      '#type' => 'number',
      '#title' => $this->t('Phrase near multiplier'),
      '#default_value' => $config->get('scoring.phrase_near_multiplier') ?? 1.5,
      '#step' => 'any',
      '#min' => 0,
      '#description' => $this->t('Boost multiplier when query terms appear within the near window.'),
    ];

    $form['scoring']['phrase_near_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Phrase near window'),
      '#default_value' => $config->get('scoring.phrase_near_window') ?? 5,
      '#min' => 1,
      '#description' => $this->t('Maximum word distance for phrase near multiplier.'),
    ];

    $form['scoring']['phrase_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Phrase window'),
      '#default_value' => $config->get('scoring.phrase_window') ?? 15,
      '#min' => 1,
      '#description' => $this->t('Maximum word distance for phrase proximity scoring.'),
    ];

    $form['scoring']['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Scoring language'),
      '#default_value' => $config->get('scoring.language') ?? 'en',
      '#options' => [
        'ar' => $this->t('Arabic (ar)'),
        'ca' => $this->t('Catalan (ca)'),
        'da' => $this->t('Danish (da)'),
        'de' => $this->t('German (de)'),
        'el' => $this->t('Greek (el)'),
        'en' => $this->t('English (en)'),
        'es' => $this->t('Spanish (es)'),
        'et' => $this->t('Estonian (et)'),
        'eu' => $this->t('Basque (eu)'),
        'fi' => $this->t('Finnish (fi)'),
        'fr' => $this->t('French (fr)'),
        'ga' => $this->t('Irish (ga)'),
        'hi' => $this->t('Hindi (hi)'),
        'hu' => $this->t('Hungarian (hu)'),
        'hy' => $this->t('Armenian (hy)'),
        'id' => $this->t('Indonesian (id)'),
        'it' => $this->t('Italian (it)'),
        'lt' => $this->t('Lithuanian (lt)'),
        'ne' => $this->t('Nepali (ne)'),
        'nl' => $this->t('Dutch (nl)'),
        'no' => $this->t('Norwegian (no)'),
        'pl' => $this->t('Polish (pl)'),
        'pt' => $this->t('Portuguese (pt)'),
        'ro' => $this->t('Romanian (ro)'),
        'ru' => $this->t('Russian (ru)'),
        'sr' => $this->t('Serbian (sr)'),
        'sv' => $this->t('Swedish (sv)'),
        'ta' => $this->t('Tamil (ta)'),
        'tr' => $this->t('Turkish (tr)'),
        'yi' => $this->t('Yiddish (yi)'),
      ],
      '#description' => $this->t('ISO 639-1 language code used for stop word filtering during scoring. Choose the primary language of your site content.'),
    ];

    $form['scoring']['custom_stop_words'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom stop words'),
      '#default_value' => implode(', ', $config->get('scoring.custom_stop_words') ?? []),
      '#rows' => 3,
      '#description' => $this->t('Comma-separated additional stop words to exclude from scoring, beyond the built-in language list. e.g. <code>drupal, cms, site</code>'),
    ];

    $form['scoring']['expand_subword_deny_list'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sub-word guard denylist'),
      '#default_value' => implode(', ', $config->get('scoring.expand_subword_deny_list') ?? []),
      '#rows' => 2,
      '#description' => $this->t('Comma-separated words that are never auto-exempted from the sub-word frequency guard, even when typed (e.g. a generic word like <code>hot</code> on a recipe site). Unlike custom stop words, these stay searchable and scorable. Leave empty unless a typed common word floods results.'),
    ];

    $form['scoring']['recency_strategy'] = [
      '#type' => 'select',
      '#title' => $this->t('Recency strategy'),
      '#default_value' => $config->get('scoring.recency_strategy') ?? 'exponential',
      '#options' => [
        'exponential' => $this->t('Exponential (default)'),
        'linear' => $this->t('Linear'),
        'step' => $this->t('Step'),
        'none' => $this->t('None (disable recency scoring)'),
        'custom' => $this->t('Custom (piecewise-linear curve)'),
      ],
      '#description' => $this->t('Decay function for recency boost. <em>Custom</em> uses the control points below.'),
    ];

    $form['scoring']['recency_curve'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom recency curve'),
      '#default_value' => $config->get('scoring.recency_curve') ? json_encode($config->get('scoring.recency_curve')) : '',
      '#rows' => 3,
      '#description' => $this->t('JSON array of <code>[days, boost]</code> control points for the custom strategy, sorted by days. e.g. <code>[[0, 1.0], [180, 0.5], [365, 0.0]]</code>'),
      '#states' => [
        'visible' => [
          ':input[name="recency_strategy"]' => ['value' => 'custom'],
        ],
      ],
    ];

    // ── Display Section ──
    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display'),
      '#open' => FALSE,
    ];

    $form['display']['excerpt_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Excerpt length'),
      '#default_value' => $config->get('display.excerpt_length') ?? 300,
      '#min' => 50,
      '#max' => 2000,
      '#description' => $this->t('Maximum character length for result excerpts.'),
    ];

    $form['display']['results_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Results per page'),
      '#default_value' => $config->get('display.results_per_page') ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of search results per page.'),
    ];

    $form['display']['max_pagefind_results'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Pagefind results'),
      '#default_value' => $config->get('display.max_pagefind_results') ?? 50,
      '#min' => 1,
      '#max' => 500,
      '#description' => $this->t('Maximum results to fetch from Pagefind before scoring.'),
    ];

    $form['display']['ai_summary_top_n'] = [
      '#type' => 'number',
      '#title' => $this->t('AI summary top N results'),
      '#default_value' => $config->get('display.ai_summary_top_n') ?? 5,
      '#min' => 1,
      '#max' => 20,
      '#description' => $this->t('Number of top results to send to AI for summarization.'),
    ];

    $form['display']['ai_summary_max_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('AI summary max characters'),
      '#default_value' => $config->get('display.ai_summary_max_chars') ?? 4000,
      '#min' => 100,
      '#max' => 10000,
      '#description' => $this->t('Maximum characters of context sent to AI for summarization.'),
    ];

    $form['display']['show_attribution'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Scolta attribution on search page'),
      '#default_value' => $config->get('show_attribution') ?? FALSE,
      '#description' => $this->t('When enabled, a "Powered by Scolta" notice is appended to the search block output.'),
    ];

    $form['display']['hide_empty_facets'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide empty facet values'),
      '#default_value' => $config->get('hide_empty_facets') ?? TRUE,
      '#description' => $this->t('When enabled (default), a facet value with zero results for the current query is hidden, and a filter group whose values are all zero is dropped. An active (checked) value stays visible so it can be unchecked. Disable to show every value, rendering a zero-count one as a disabled "(0)" option.'),
    ];

    $form['display']['facet_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Facet index loading'),
      '#options' => [
        'eager' => $this->t('Eager — load with the search page (default)'),
        'deferred' => $this->t('Deferred — load on the first facet interaction'),
        'disabled' => $this->t('Disabled — never load, and show no filters'),
      ],
      '#default_value' => $config->get('facet_mode') ?? 'eager',
      '#description' => $this->t('Controls when the browser downloads the facet index, which on a large site can reach a megabyte or more. <strong>Eager</strong> loads it with the search page, so the filter sidebar is populated before the first results paint. <strong>Deferred</strong> skips that download and takes it the first time a visitor actually uses a filter — useful when a theme renders its own facets, though the Scolta filter sidebar then stays empty until that first interaction, because it is built from the index. <strong>Disabled</strong> never downloads it: no filter sidebar, no facet filtering, and the per-query count pass is skipped as well. Filtering stays correct and fast under Deferred: the index finishes loading before the filter is applied, so it never falls back to the slower per-search filtering it exists to replace.'),
    ];

    // ── Search As You Type Section ──
    $form['sayt'] = [
      '#type' => 'details',
      '#title' => $this->t('Search as you type'),
      '#open' => FALSE,
      '#description' => $this->t('Typing in the search box opens a suggestions dropdown underneath it. Typing alone never runs a search: the full pipeline (AI expansion, summary, follow-ups) still waits for Enter, the search button, or a selected suggestion. Existing indexes need no rebuild — suggestions read the same fragments the result list does.'),
    ];

    $form['sayt']['sayt_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable search as you type'),
      '#default_value' => $config->get('sayt_enabled') ?? TRUE,
      '#description' => $this->t('When disabled, the search widget behaves exactly as it did before this feature existed: no dropdown, no combobox roles on the input, and nothing read from or written to browser storage.'),
    ];

    $form['sayt']['sayt_min_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum characters'),
      '#default_value' => $config->get('sayt_min_chars') ?? 2,
      '#min' => 1,
      '#max' => 10,
      '#description' => $this->t('How much the visitor must type before suggestions are fetched. Counted in characters as a person sees them, so an emoji or a Devanagari cluster counts as one. Sites in Chinese, Japanese or Korean generally want 1: a single character is already a meaningful query.'),
    ];

    $form['sayt']['sayt_debounce_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Debounce (milliseconds)'),
      '#default_value' => $config->get('sayt_debounce_ms') ?? 150,
      '#min' => 0,
      '#max' => 2000,
      '#description' => $this->t('Idle time after the last keystroke before a suggestion pass runs. Default: 150.'),
    ];

    $form['sayt']['sayt_max_suggestions'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum suggestions'),
      '#default_value' => $config->get('sayt_max_suggestions') ?? 6,
      '#min' => 1,
      '#max' => 20,
      '#description' => $this->t('Rows shown in the dropdown, and the hard cap on how many result fragments each pass loads.'),
    ];

    $form['sayt']['sayt_recent_searches'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Offer recent searches'),
      '#default_value' => $config->get('sayt_recent_searches') ?? TRUE,
      '#description' => $this->t('Suggest the visitor their own recent searches, stored in their browser. When disabled, nothing is read from or written to browser storage.'),
    ];

    $form['sayt']['sayt_max_recent'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum recent searches'),
      '#default_value' => $config->get('sayt_max_recent') ?? 3,
      '#min' => 0,
      '#max' => 10,
      '#description' => $this->t('How many recent searches the dropdown shows.'),
    ];

    $form['sayt']['sayt_expand'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enrich suggestions with AI query expansion'),
      '#default_value' => $config->get('sayt_expand') ?? TRUE,
      '#description' => $this->t('Once typing settles, run one query expansion for the typed prefix and merge the documents it finds into the dropdown. Inert when AI query expansion is off or no AI provider is configured.'),
    ];

    $form['sayt']['sayt_expand_per_minute'] = [
      '#type' => 'number',
      '#title' => $this->t('AI enrichment calls per minute'),
      '#default_value' => $config->get('sayt_expand_per_minute') ?? 6,
      '#min' => 0,
      '#max' => 60,
      '#description' => $this->t("Cap on suggestion-driven AI expansion calls per visitor per minute. It exists because these expansions share the AI flood budget with committed searches: expansion, summarize and follow-up all count against the same per-IP limit above, so an unbudgeted suggest path would spend a visitor's whole allowance on prefixes they never submitted and leave the search they actually ran with no expansion and no summary. Over the cap, suggestions silently fall back to keyword matches."),
    ];

    $form['sayt']['sayt_expansion_delay_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('AI enrichment delay (milliseconds)'),
      '#default_value' => $config->get('sayt_expansion_delay_ms') ?? 500,
      '#min' => 0,
      '#max' => 5000,
      '#description' => $this->t('Idle time before the AI enrichment call fires. Deliberately longer than the debounce above: keyword suggestions should appear while typing, an AI call should not. Default: 500.'),
    ];

    $form['sayt']['sayt_suggestion_action'] = [
      '#type' => 'radios',
      '#title' => $this->t('Selecting a suggestion'),
      // Anything unrecognized clamps to 'navigate', matching what the browser
      // bundle does with a value it does not know.
      '#default_value' => $config->get('sayt_suggestion_action') === 'search' ? 'search' : 'navigate',
      '#options' => [
        'navigate' => $this->t('Go to that result'),
        'search' => $this->t('Search for that title'),
      ],
      '#description' => $this->t('"Go to that result" renders each suggestion as a real link, so middle-click, ctrl-click and "copy link address" work — choose it when suggestions are documents the visitor wants to open. "Search for that title" puts the suggestion in the box and runs the full search, AI summary and all — choose it when the value is in the result set rather than the single document. A recent-search suggestion always runs the search, whichever option is selected: navigating to a stored query string is meaningless.'),
    ];

    // ── Cache Section ──
    $form['cache'] = [
      '#type' => 'details',
      '#title' => $this->t('Cache'),
      '#open' => FALSE,
    ];

    $form['cache']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#default_value' => $config->get('cache_ttl') ?? 2592000,
      '#min' => 0,
      '#description' => $this->t('Cache lifetime for AI responses in seconds. Set to 0 to disable caching. Default: 2592000 (30 days).'),
    ];

    // ── Rate Limiting Section ──
    $form['flood'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate Limiting'),
      '#open' => FALSE,
      '#description' => $this->t('The AI API endpoints (expand, summarize, follow-up) make cost-bearing LLM calls and are reachable by anonymous visitors by default. These thresholds reject excess requests with HTTP 429 before any AI call is made.'),
    ];

    $form['flood']['flood_ai_ip_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Per-IP request limit'),
      '#default_value' => $config->get('flood.ai_ip_limit') ?? 60,
      '#min' => 0,
      '#description' => $this->t('Maximum AI API requests allowed per client IP per window. Set to 0 to disable the per-IP layer. Default: 60.'),
    ];

    $form['flood']['flood_ai_ip_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Per-IP window (seconds)'),
      '#default_value' => $config->get('flood.ai_ip_window') ?? 60,
      '#min' => 1,
      '#description' => $this->t('Window for the per-IP limit. Default: 60.'),
    ];

    $form['flood']['flood_ai_global_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Site-wide request limit'),
      '#default_value' => $config->get('flood.ai_global_limit') ?? 1000,
      '#min' => 0,
      '#description' => $this->t('Maximum AI API requests allowed across all visitors per window — a backstop against distributed abuse. Set to 0 to disable the global layer. Default: 1000.'),
    ];

    $form['flood']['flood_ai_global_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Site-wide window (seconds)'),
      '#default_value' => $config->get('flood.ai_global_window') ?? 60,
      '#min' => 1,
      '#description' => $this->t('Window for the site-wide limit. Default: 60.'),
    ];

    // ── Custom Prompts Section ──
    $form['prompts'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom Prompts'),
      '#open' => FALSE,
      '#description' => $this->t('Edit the AI prompts below. The default prompt is shown when no custom value is saved. Clear the field and save to reset to the default. Supports {SITE_NAME} and {SITE_DESCRIPTION} placeholders.'),
    ];

    $form['prompts']['prompt_expand_query'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Expand query prompt'),
      '#default_value' => $this->getEffectivePrompt($config, 'prompt_expand_query', 'expand_query'),
      '#rows' => 8,
      '#description' => $this->getPromptDescription($config, 'prompt_expand_query'),
    ];

    $form['prompts']['prompt_summarize'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Summarize prompt'),
      '#default_value' => $this->getEffectivePrompt($config, 'prompt_summarize', 'summarize'),
      '#rows' => 8,
      '#description' => $this->getPromptDescription($config, 'prompt_summarize'),
    ];

    $form['prompts']['prompt_follow_up'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Follow-up prompt'),
      '#default_value' => $this->getEffectivePrompt($config, 'prompt_follow_up', 'follow_up'),
      '#rows' => 8,
      '#description' => $this->getPromptDescription($config, 'prompt_follow_up'),
    ];

    // ── Status Section (read-only) ──
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Status'),
      '#open' => FALSE,
    ];

    $form['status']['info'] = $this->buildStatusInfo();

    $form = parent::buildForm($form, $form_state);

    // Disable browser HTML5 validation: number fields inside a collapsed
    // <details> cannot be focused when invalid, silently blocking submit.
    // Drupal handles all validation server-side.
    $form['#attributes']['novalidate'] = 'novalidate';

    $form['actions']['rebuild_index'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rebuild Index'),
      '#name' => 'rebuild_index',
      '#submit' => ['::rebuildSubmit'],
      '#limit_validation_errors' => [],
      '#weight' => 10,
    ];

    return $form;
  }

  /**
   * Build the API key status display element.
   */
  protected function buildApiKeyStatus(): array {
    // Derived from the one resolution the client performs, never from a
    // second look at the credential store. This message claimed a site was
    // connected to Amazee.ai whenever credentials were stored, including when
    // an explicit SCOLTA_API_KEY was serving every request — and said so in
    // success green, which is how it came to be read as proof that the
    // environment variable was missing (scolta-php#252).
    $resolved = $this->aiService->resolveApiKey();

    switch ($resolved->source) {
      // Three Amazee cases, each stating only what the credential store
      // recorded when the connection was made. Provenance used to be
      // underivable, which is why the free-trial claim was removed outright
      // (scolta-php#273); it is now written at connect time, so the demo and an
      // operator's own account are distinguishable from a stored fact. The
      // origin-free case covers connections made before that and claims
      // nothing.
      case ApiKeySource::AmazeeDemo:
        $amazee_url = Url::fromRoute('scolta.settings.amazee')->toString();
        $message = $this->t('Connected to <a href="@url">Amazee.ai</a> using the free demo.', ['@url' => $amazee_url]);
        break;

      case ApiKeySource::AmazeeAccount:
        $amazee_url = Url::fromRoute('scolta.settings.amazee')->toString();
        $message = $this->t('Connected to <a href="@url">Amazee.ai</a> with your account.', ['@url' => $amazee_url]);
        break;

      case ApiKeySource::Amazee:
        $amazee_url = Url::fromRoute('scolta.settings.amazee')->toString();
        $message = $this->t('Connected to <a href="@url">Amazee.ai</a>.', ['@url' => $amazee_url]);
        break;

      case ApiKeySource::Env:
        $message = $this->t('API key configured via SCOLTA_API_KEY environment variable.');
        break;

      case ApiKeySource::Settings:
        $message = $this->t("API key configured via settings.php (\$settings['scolta.api_key']).");
        break;

      default:
        $message = $this->t("No API key configured. Set the SCOLTA_API_KEY environment variable or add \$settings['scolta.api_key'] to settings.php.");
        break;
    }

    // Each sentence is translated on its own and rendered before joining, so
    // the markup inside one is not escaped by being a placeholder in another.
    $sentences = [(string) $message];

    // Say what happened to credentials the operator knows they created,
    // rather than leaving the override invisible.
    if ($resolved->amazeeOverridden()) {
      // Two ways a stored connection can be idle, and they need different
      // fixes: an explicit key outranks it, or Amazee.ai is simply not the
      // selected provider. Naming the wrong one sends the operator hunting
      // for an environment variable that was never set.
      $sentences[] = $resolved->source === ApiKeySource::None
        ? (string) $this->t(
          '<a href="@url">Amazee.ai</a> credentials stored but not in use, because Amazee.ai is not the selected AI provider.',
          ['@url' => Url::fromRoute('scolta.settings.amazee')->toString()]
        )
        : (string) $this->t(
          '<a href="@url">Amazee.ai</a> credentials stored but overridden by @source.',
          [
            '@url' => Url::fromRoute('scolta.settings.amazee')->toString(),
            '@source' => $resolved->source === ApiKeySource::Env
              ? 'SCOLTA_API_KEY'
              : "settings.php (\$settings['scolta.api_key'])",
          ]
        );
    }

    if ($resolved->awaitingAmazeeModelResolution) {
      $sentences[] = (string) $this->t('Model resolution has not completed, so AI features stay degraded until it does.');
    }

    // severity() is what keeps an overridden credential out of success green.
    $class = $resolved->severity() === 'ok' ? 'color--success' : 'color--warning';

    return [
      '#type' => 'item',
      '#title' => $this->t('API Key Status'),
      '#markup' => '<span class="' . $class . '">' . implode(' ', $sentences) . '</span>',
    ];
  }

  /**
   * Build the status information display.
   */
  protected function buildStatusInfo(): array {
    $items = [];
    $config = $this->config('scolta.settings');

    // AI provider status.
    // No coalescing to a provider nobody chose. An empty value means AI is off,
    // and the status line has to say that rather than name Anthropic.
    $activeProvider = $config->get('ai_provider') ?? '';
    if ($activeProvider === '') {
      $items[] = $this->t('AI provider: none selected — AI features are off. Search works without one; choose a provider above to turn AI on.');
    }
    elseif ($activeProvider === 'drupal_ai') {
      if ($this->aiService->hasDrupalAiModule()) {
        $items[] = $this->t('AI provider: Drupal AI module (routing through configured default provider).');
      }
      else {
        $items[] = $this->t('AI provider: Drupal AI module selected but not installed — falling back to built-in AiClient. Install the AI module (ai:ai) or select a different provider.');
      }
    }
    else {
      $items[] = $this->t('AI provider: Built-in AiClient (@provider). <a href="@url">Install the Drupal AI module</a> for 48+ provider support with Key module integration.', [
        '@provider' => $activeProvider,
        '@url' => Url::fromUserInput('/admin/config/ai/providers')->toString(),
      ]);
    }

    // Pagefind binary status.
    $resolver = new PagefindBinary(
      configuredPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );
    $binaryStatus = $resolver->status();
    if ($binaryStatus['available']) {
      $items[] = $this->t('Pagefind binary: @message', [
        '@message' => $binaryStatus['message'],
      ]);
    }
    else {
      $items[] = $this->t('Pagefind binary: Not available. Run drush scolta:download-pagefind or install via npm.');
    }

    // Build directory status.
    $buildDirConfig = $config->get('pagefind.build_dir') ?? 'public://scolta-build';
    $resolvedBuildDir = $this->resolveStateDir($config);
    if ($resolvedBuildDir !== $buildDirConfig) {
      $items[] = $this->t('Build directory: @configured (resolved to @resolved)', [
        '@configured' => $buildDirConfig,
        '@resolved' => $resolvedBuildDir,
      ]);
    }
    else {
      $items[] = $this->t('Build directory: @path', ['@path' => $resolvedBuildDir]);
    }

    // Pagefind index status.
    $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    if (str_contains($outputDir, '://')) {
      try {
        $resolvedDir = $this->streamWrapperManager
          ->getViaUri($outputDir)->realpath() ?: $outputDir;
      }
      catch (\Exception $e) {
        $resolvedDir = $outputDir;
      }
    }
    else {
      $resolvedDir = $outputDir;
    }

    $indexStatus = $this->pagefindBuilder->getStatus($resolvedDir);
    if ($indexStatus['exists']) {
      $items[] = $this->t('Pagefind index: Built (@size, @count fragments, last built @date)', [
        '@size' => $indexStatus['index_size'],
        '@count' => $indexStatus['file_count'],
        '@date' => $indexStatus['last_built'] ?? 'unknown',
      ]);
    }
    else {
      $items[] = $this->t('Pagefind index: Not built yet. Run Search API indexing or drush scolta:build.');
    }

    // Search API index.
    try {
      $indexes = $this->entityTypeManager
        ->getStorage('search_api_index')
        ->loadByProperties(['server' => 'scolta_pagefind']);
      if (!empty($indexes)) {
        $index = reset($indexes);
        $items[] = $this->t('Search API index: @label (@status)', [
          '@label' => $index->label(),
          '@status' => $index->status() ? 'enabled' : 'disabled',
        ]);
      }
      else {
        // Try loading any index with scolta backend.
        $allIndexes = $this->entityTypeManager
          ->getStorage('search_api_index')
          ->loadMultiple();
        $found = FALSE;
        foreach ($allIndexes as $index) {
          if ($index->getServerId() && str_contains($index->getServerId(), 'scolta')) {
            $items[] = $this->t('Search API index: @label (@status)', [
              '@label' => $index->label(),
              '@status' => $index->status() ? 'enabled' : 'disabled',
            ]);
            $found = TRUE;
            break;
          }
        }
        if (!$found) {
          $items[] = $this->t('Search API index: No Scolta index configured. Create a Search API server with the Scolta (Pagefind) backend.');
        }
      }
    }
    catch (\Exception $e) {
      $items[] = $this->t('Search API index: Unable to query (@msg)', [
        '@msg' => $e->getMessage(),
      ]);
    }

    $list = '<ul>';
    foreach ($items as $item) {
      $list .= '<li>' . $item . '</li>';
    }
    $list .= '</ul>';

    return [
      '#type' => 'item',
      '#markup' => $list,
    ];
  }

  /**
   * Get the effective prompt: saved custom value, or the built-in default.
   */
  protected function getEffectivePrompt($config, string $configKey, string $templateName): string {
    $saved = $config->get($configKey) ?? '';
    if (!empty($saved)) {
      return $saved;
    }
    return $this->getDefaultPrompt($templateName);
  }

  /**
   * Get the description text for a prompt field.
   *
   * Indicates the current customization state.
   */
  protected function getPromptDescription($config, string $configKey): string {
    $saved = $config->get($configKey) ?? '';
    if (!empty($saved)) {
      return (string) $this->t('Customized. Clear the field and save to reset to the built-in default.');
    }
    return (string) $this->t('Showing the built-in default. Edit to customize, or leave as-is.');
  }

  /**
   * Get the default prompt template text.
   *
   * Returns the raw template with {SITE_NAME} and {SITE_DESCRIPTION}
   * placeholders intact. Returns empty string if the template is not found.
   */
  protected function getDefaultPrompt(string $name): string {
    try {
      return DefaultPrompts::getTemplate($name);
    }
    catch (\Throwable $e) {
      $this->getLogger('scolta')->warning(
        'Failed to load default prompt "@name": @msg',
        ['@name' => $name, '@msg' => $e->getMessage()]
      );
      return (string) $this->t(
        'Default prompt unavailable. Run "drush scolta:check-setup" for diagnostics.'
      );
    }
  }

  /**
   * If a prompt value matches the built-in default, store empty string.
   *
   * This ensures the prompt automatically picks up future default changes
   * from library updates, rather than persisting a stale copy.
   */
  protected function normalizePromptValue(string $value, string $templateName): string {
    $default = $this->getDefaultPrompt($templateName);
    if ($default !== '' && trim($value) === trim($default)) {
      return '';
    }
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $baseUrl = trim($form_state->getValue('ai_base_url') ?? '');
    if ($baseUrl !== '') {
      $parsed = parse_url($baseUrl);
      $scheme = $parsed['scheme'] ?? '';
      if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], TRUE)) {
        $form_state->setErrorByName(
          'ai_base_url',
          $this->t('The API Base URL must be a valid URL beginning with http:// or https://.')
        );
      }
    }

    // Reject malformed recency-curve JSON instead of silently discarding it
    // on save (json_decode(...) ?: [] would wipe the input without feedback).
    $recencyCurve = trim($form_state->getValue('recency_curve') ?? '');
    if ($recencyCurve !== '') {
      $decoded = json_decode($recencyCurve, TRUE);
      if (!is_array($decoded)) {
        $form_state->setErrorByName(
          'recency_curve',
          $this->t('The custom recency curve must be a JSON array of [days, boost] control points, e.g. [[0, 1.0], [365, 0.0]].')
        );
      }
    }

    // Reject key|value lines without a pipe instead of silently dropping them.
    $pipeFields = [
      'sortable_field_descriptions' => $this->t('Sortable field descriptions'),
      'filter_field_descriptions' => $this->t('Filter field descriptions'),
      'field_mapping_sortable' => $this->t('Sortable field mappings'),
      'field_mapping_filters' => $this->t('Filter field mappings'),
    ];
    foreach ($pipeFields as $fieldName => $label) {
      $raw = (string) ($form_state->getValue($fieldName) ?? '');
      foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line !== '' && !str_contains($line, '|')) {
          $form_state->setErrorByName(
            $fieldName,
            $this->t('@label: the line "@line" is missing the "|" separator. Use one key|value pair per line.', [
              '@label' => $label,
              '@line' => $line,
            ])
          );
          break;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $presetName = $form_state->getValue('preset') ?? 'none';
    $validPresets = array_keys(ScoltaConfig::getPresets());
    if (!in_array($presetName, $validPresets, TRUE)) {
      $presetName = 'none';
    }

    // Read before the save below overwrites it: whether the operator is
    // switching away from the managed gateway is only knowable here.
    $previousProvider = $this->config('scolta.settings')->get('ai_provider');

    $configSave = $this->config('scolta.settings')
      ->set('preset', $presetName);

    // Apply preset values first; explicit form values override below.
    // Keys that live under display.* rather than scoring.*.
    $displayKeys = [
      'ai_summary_top_n',
      'max_pagefind_results',
      'results_per_page',
      'excerpt_length',
      'ai_summary_max_chars',
    ];
    if ($presetName !== 'none') {
      foreach (ScoltaConfig::getPresetValues($presetName) as $key => $value) {
        $prefix = in_array($key, $displayKeys, TRUE) ? 'display.' : 'scoring.';
        $configSave->set($prefix . $key, $value);
      }
    }

    $configSave
      // AI settings.
      ->set('ai_provider', $form_state->getValue('ai_provider'))
      ->set('ai_model', $form_state->getValue('ai_model'))
      ->set('ai_expansion_model', $form_state->getValue('ai_expansion_model') ?? '')
      ->set('ai_base_url', $form_state->getValue('ai_base_url'))
      ->set('ai_expand_query', (bool) $form_state->getValue('ai_expand_query'))
      ->set('ai_summarize', (bool) $form_state->getValue('ai_summarize'))
      ->set('ai_languages', array_values(array_filter(array_map(
        'trim',
        explode(',', $form_state->getValue('ai_languages') ?? 'en')
      ))) ?: ['en'])
      ->set('max_follow_ups', (int) $form_state->getValue('max_follow_ups'))
      // Content settings.
      ->set('site_name', $form_state->getValue('site_name'))
      ->set('site_description', $form_state->getValue('site_description'))
      ->set('body_fields', array_values(array_filter(array_map(
        'trim',
        explode(',', $form_state->getValue('body_fields') ?? '')
      ))))
      ->set('sortable_fields', array_values(array_filter(array_map(
        'trim',
        explode(',', $form_state->getValue('sortable_fields') ?? '')
      ))))
      ->set('sortable_field_descriptions', $this->parseKeyValueLines($form_state->getValue('sortable_field_descriptions') ?? ''))
      ->set('filter_fields', array_values(array_filter(array_map(
        'trim',
        explode(',', $form_state->getValue('filter_fields') ?? '')
      ))))
      ->set('filter_field_descriptions', $this->parseKeyValueLines($form_state->getValue('filter_field_descriptions') ?? ''))
      ->set('field_mappings.sortable', $this->parseKeyValueLines($form_state->getValue('field_mapping_sortable') ?? ''))
      ->set('field_mappings.filters', $this->parseKeyValueLines($form_state->getValue('field_mapping_filters') ?? ''))
      ->set('indexer', $form_state->getValue('indexer'))
      ->set('memory_budget.profile', $form_state->getValue('memory_budget_profile') ?? 'conservative')
      ->set('memory_budget.custom_bytes', NULL)
      ->set('memory_budget.chunk_size', ($form_state->getValue('chunk_size') !== '' && $form_state->getValue('chunk_size') !== NULL) ? (int) $form_state->getValue('chunk_size') : NULL)
      // Scoring settings.
      ->set('scoring.title_match_boost', (float) $form_state->getValue('title_match_boost'))
      ->set('scoring.title_all_terms_multiplier', (float) $form_state->getValue('title_all_terms_multiplier'))
      ->set('scoring.content_match_boost', (float) $form_state->getValue('content_match_boost'))
      ->set('scoring.recency_boost_max', (float) $form_state->getValue('recency_boost_max'))
      ->set('scoring.recency_half_life_days', (int) $form_state->getValue('recency_half_life_days'))
      ->set('scoring.recency_penalty_after_days', (int) $form_state->getValue('recency_penalty_after_days'))
      ->set('scoring.recency_max_penalty', (float) $form_state->getValue('recency_max_penalty'))
      ->set('scoring.expand_primary_weight', (float) $form_state->getValue('expand_primary_weight'))
      ->set('scoring.cross_list_bonus', (float) $form_state->getValue('cross_list_bonus'))
      ->set('scoring.expand_subword_max_frequency', (float) $form_state->getValue('expand_subword_max_frequency'))
      ->set('scoring.specificity_weighting', (bool) $form_state->getValue('specificity_weighting'))
      ->set('scoring.specificity_floor', (float) $form_state->getValue('specificity_floor'))
      ->set('scoring.specificity_strong_match', (float) $form_state->getValue('specificity_strong_match'))
      ->set('scoring.specificity_cooccurrence', (float) $form_state->getValue('specificity_cooccurrence'))
      ->set('scoring.specificity_agreement_gate', (float) $form_state->getValue('specificity_agreement_gate'))
      ->set('scoring.specificity_agreement_decay', (float) $form_state->getValue('specificity_agreement_decay'))
      ->set('scoring.expansion_combine_mode', in_array($form_state->getValue('expansion_combine_mode'), [
        'relevance_union', 'round_robin',
      ], TRUE) ? $form_state->getValue('expansion_combine_mode') : 'relevance_union')
      ->set('scoring.exact_title_match_boost', (float) $form_state->getValue('exact_title_match_boost'))
      ->set('scoring.phrase_adjacent_multiplier', (float) $form_state->getValue('phrase_adjacent_multiplier'))
      ->set('scoring.phrase_near_multiplier', (float) $form_state->getValue('phrase_near_multiplier'))
      ->set('scoring.phrase_near_window', (int) $form_state->getValue('phrase_near_window'))
      ->set('scoring.phrase_window', (int) $form_state->getValue('phrase_window'))
      ->set('scoring.language', $form_state->getValue('language') ?? 'en')
      ->set('scoring.custom_stop_words', array_values(array_filter(array_map(
        'trim',
        explode(',', $form_state->getValue('custom_stop_words') ?? '')
      ))))
      ->set('scoring.expand_subword_deny_list', array_values(array_filter(array_map(
        fn($w) => strtolower(trim($w)),
        explode(',', $form_state->getValue('expand_subword_deny_list') ?? '')
      ))))
      ->set('scoring.recency_strategy', in_array($form_state->getValue('recency_strategy'), [
        'exponential', 'linear', 'step', 'none', 'custom',
      ], TRUE) ? $form_state->getValue('recency_strategy') : 'exponential')
      ->set('scoring.recency_curve', json_decode($form_state->getValue('recency_curve') ?? '[]', TRUE) ?: [])
      // Display settings.
      ->set('display.excerpt_length', (int) $form_state->getValue('excerpt_length'))
      ->set('display.results_per_page', (int) $form_state->getValue('results_per_page'))
      ->set('display.max_pagefind_results', (int) $form_state->getValue('max_pagefind_results'))
      ->set('display.ai_summary_top_n', (int) $form_state->getValue('ai_summary_top_n'))
      ->set('display.ai_summary_max_chars', (int) $form_state->getValue('ai_summary_max_chars'))
      // Display: attribution.
      ->set('show_attribution', (bool) $form_state->getValue('show_attribution'))
      // Display: facet visibility.
      ->set('hide_empty_facets', (bool) $form_state->getValue('hide_empty_facets'))
      // An unrecognized mode clamps to 'eager', as ScoltaConfig and the bundle
      // both do: a bad value must cost a site nothing.
      ->set('facet_mode', in_array($form_state->getValue('facet_mode'), ['eager', 'deferred', 'disabled'], TRUE)
        ? $form_state->getValue('facet_mode')
        : 'eager')
      // Search as you type. The bounds repeat the #min/#max on the fields:
      // those are enforced server-side and an out-of-range value never reaches
      // here, so this is a floor under a value arriving by any other route.
      ->set('sayt_enabled', (bool) $form_state->getValue('sayt_enabled'))
      ->set('sayt_min_chars', max(1, (int) $form_state->getValue('sayt_min_chars')))
      ->set('sayt_debounce_ms', max(0, (int) $form_state->getValue('sayt_debounce_ms')))
      ->set('sayt_max_suggestions', max(1, (int) $form_state->getValue('sayt_max_suggestions')))
      ->set('sayt_recent_searches', (bool) $form_state->getValue('sayt_recent_searches'))
      ->set('sayt_max_recent', max(0, (int) $form_state->getValue('sayt_max_recent')))
      ->set('sayt_expand', (bool) $form_state->getValue('sayt_expand'))
      ->set('sayt_expand_per_minute', max(0, (int) $form_state->getValue('sayt_expand_per_minute')))
      ->set('sayt_expansion_delay_ms', max(0, (int) $form_state->getValue('sayt_expansion_delay_ms')))
      ->set('sayt_suggestion_action', $form_state->getValue('sayt_suggestion_action') === 'search' ? 'search' : 'navigate')
      // Cache.
      ->set('cache_ttl', (int) $form_state->getValue('cache_ttl'))
      // Rate limiting.
      ->set('flood.ai_ip_limit', max(0, (int) $form_state->getValue('flood_ai_ip_limit')))
      ->set('flood.ai_ip_window', max(1, (int) $form_state->getValue('flood_ai_ip_window')))
      ->set('flood.ai_global_limit', max(0, (int) $form_state->getValue('flood_ai_global_limit')))
      ->set('flood.ai_global_window', max(1, (int) $form_state->getValue('flood_ai_global_window')))
      // Custom prompts.
      ->set('prompt_expand_query', $this->normalizePromptValue($form_state->getValue('prompt_expand_query') ?? '', 'expand_query'))
      ->set('prompt_summarize', $this->normalizePromptValue($form_state->getValue('prompt_summarize') ?? '', 'summarize'))
      ->set('prompt_follow_up', $this->normalizePromptValue($form_state->getValue('prompt_follow_up') ?? '', 'follow_up'))
      ->save();

    if ($previousProvider === 'amazee' && $form_state->getValue('ai_provider') !== 'amazee') {
      $this->clearManagedGatewayFootprint();
      $this->messenger()->addStatus($this->t('The stored Amazee.ai connection has been removed, because Amazee.ai is no longer the selected AI provider. Select it again to reconnect.'));
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Remove the managed gateway's stored connection and its recovery markers.
   *
   * Called when the operator selects a different AI provider. Leaving the
   * connection in place is what let a stored gateway shadow an operator's own
   * key: every status surface reported the site as connected to Amazee.ai, and
   * once the connection expired the operator was shown a reconnect prompt for a
   * gateway they had already moved off and no way to clear it from the UI. The
   * connection is re-established by selecting Amazee.ai again and completing
   * the connect flow, which is the only thing that establishes one at all.
   *
   * The two recovery markers go with it. They describe the connection being
   * removed, so on their own they would outlive it: the upgrade-needed marker
   * never expires, and the auth-failure marker keeps /health reporting AI as
   * degraded until a successful AI call clears it — which cannot happen once
   * the credentials it refers to are gone.
   */
  private function clearManagedGatewayFootprint(): void {
    $this->amazeeConfigStorage->clear();
    $this->aiService->clearAmazeeReauthNeeded();
    $this->aiService->clearAmazeeAuthFailure();
  }

  /**
   * Submit handler for the "Rebuild Index" button.
   *
   * Gathers content from Drupal entities and routes to the PHP indexer
   * (via Batch API) or the binary indexer (synchronously) based on the
   * configured indexer mode.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function rebuildSubmit(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('scolta.settings');
    $siteName = $config->get('site_name') ?: ($this->config('system.site')->get('name') ?? '');

    // Clear any previous notice so a fresh notice_id is used after this
    // rebuild.
    $this->state->delete('scolta.rebuild_notice');

    // Resolve indexer mode up front so we choose the right gather strategy.
    $indexerMode = $config->get('indexer') ?: 'auto';
    if ($indexerMode === 'auto') {
      $indexerMode = $this->resolveAutoIndexer($config);
    }

    if ($indexerMode === 'php') {
      // For the PHP indexer, only query entity IDs here. Entity loading and
      // content filtering happen inside each batch step so that no single
      // web request has to load the full corpus into memory. This prevents
      // the "Index Now" button from timing out on shared hosting at any
      // corpus size.
      $storage = $this->entityTypeManager->getStorage('node');
      $ids = array_values($storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->execute());

      if (empty($ids)) {
        $this->messenger()->addWarning($this->t('No content found to index.'));
        return;
      }

      $this->rebuildWithBatch($ids, $siteName, $config);
    }
    else {
      // Binary mode shells out to the Pagefind CLI, which shared hosting
      // does not allow. Loading all content synchronously is acceptable here
      // since binary mode is only used on hosts that support long-running
      // processes.
      $items = iterator_to_array($this->contentGatherer->gather('node', '', $siteName), FALSE);

      if (empty($items)) {
        $this->messenger()->addWarning($this->t('No content found to index.'));
        return;
      }

      $outputDir = $this->resolveOutputDir($config);
      $exporter = new ContentExporter($outputDir);
      $filteredItems = $exporter->exportToItems($items);

      if (empty($filteredItems)) {
        $this->messenger()->addWarning($this->t('No items passed content filter.'));
        return;
      }

      $this->rebuildWithBinary($filteredItems, $config);
    }
  }

  /**
   * Resolve the output directory from config, handling stream wrappers.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   *
   * @return string
   *   The resolved output directory path.
   */
  protected function resolveOutputDir($config): string {
    $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    if (str_contains($outputDir, '://')) {
      try {
        $resolved = $this->streamWrapperManager
          ->getViaUri($outputDir)->realpath() ?: $outputDir;
        return $resolved;
      }
      catch (\Exception $e) {
        return $outputDir;
      }
    }
    return $outputDir;
  }

  /**
   * Resolve the state directory from config, handling stream wrappers.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   *
   * @return string
   *   The resolved state directory path.
   */
  protected function resolveStateDir($config): string {
    $stateDir = $config->get('pagefind.build_dir') ?? 'public://scolta-build';
    if (str_contains($stateDir, '://')) {
      try {
        $wrapper = $this->streamWrapperManager->getViaUri($stateDir);
        // realpath() returns FALSE (or '' from some wrappers) when the
        // wrapper cannot resolve — treat both as unresolved.
        $resolved = $wrapper ? ($wrapper->realpath() ?: NULL) : NULL;
        if ($resolved !== NULL) {
          return $resolved;
        }
      }
      catch (\Exception $e) {
        // Fall through to fallback.
      }

      // When private:// is unavailable, fall back to public://scolta-build.
      if (str_starts_with($stateDir, 'private://')) {
        try {
          $publicWrapper = $this->streamWrapperManager->getViaUri('public://');
          $publicBase = $publicWrapper ? ($publicWrapper->realpath() ?: NULL) : NULL;
          if ($publicBase !== NULL) {
            return $publicBase . '/scolta-build';
          }
        }
        catch (\Exception $e) {
          // Fall through to original URI.
        }
      }

      return $stateDir;
    }
    return $stateDir;
  }

  /**
   * Resolve 'auto' indexer mode.
   *
   * Auto always uses the PHP indexer — it works on all PHP hosting
   * environments without shell access or Node.js. Set indexer: binary to
   * use the Pagefind binary explicitly.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config (unused, kept for API consistency).
   *
   * @return string
   *   Always 'php'.
   */
  protected function resolveAutoIndexer($config): string {
    return 'php';
  }

  /**
   * Rebuild using Batch API with the PHP indexer.
   *
   * Accepts entity IDs rather than pre-loaded ContentItems so that no single
   * web request ever loads the full corpus. Entity loading, content extraction,
   * and filtering all happen inside each batch step.
   *
   * @param array $entityIds
   *   Flat array of published node IDs to index.
   * @param string $siteName
   *   Site name passed to each ContentItem.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   */
  protected function rebuildWithBatch(array $entityIds, string $siteName, $config): void {
    $stateDir = $this->resolveStateDir($config);
    $outputDir = $this->resolveOutputDir($config);
    $language = $config->get('ai_languages')[0] ?? 'en';

    // Ensure directories exist.
    if (!is_dir($stateDir)) {
      $this->fileSystem->mkdir($stateDir, 0755, TRUE);
    }
    if (!is_dir($outputDir)) {
      $this->fileSystem->mkdir($outputDir, 0755, TRUE);
    }

    $batchConfig = [
      'state_dir' => $stateDir,
      'output_dir' => $outputDir,
      'hmac_secret' => NULL,
      'language' => $language,
    ];

    $chunkSize = 100;
    $idChunks = array_chunk($entityIds, $chunkSize);
    $totalCount = count($entityIds);

    $operations = [];
    foreach ($idChunks as $idx => $idChunk) {
      $operations[] = [
        [ScoltaBatchOperations::class, 'loadAndProcessChunk'],
        [$idx, $idChunk, $totalCount, $siteName, $batchConfig],
      ];
    }

    // Add finalize operation.
    $operations[] = [
      [ScoltaBatchOperations::class, 'finalize'],
      [$batchConfig],
    ];

    $batch = [
      'title' => $this->t('Rebuilding search index...'),
      'operations' => $operations,
      'finished' => [ScoltaBatchOperations::class, 'finished'],
      'progressive' => TRUE,
    ];

    batch_set($batch);
  }

  /**
   * Rebuild using the Pagefind binary (synchronous).
   *
   * @param \Tag1\Scolta\Export\ContentItem[] $items
   *   The filtered content items.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   */
  protected function rebuildWithBinary(array $items, $config): void {
    $outputDir = $this->resolveOutputDir($config);
    $stateDir = $this->resolveStateDir($config);

    // Ensure directories exist.
    if (!is_dir($stateDir)) {
      $this->fileSystem->mkdir($stateDir, 0755, TRUE);
    }
    if (!is_dir($outputDir)) {
      $this->fileSystem->mkdir($outputDir, 0755, TRUE);
    }

    // Export HTML files for the binary.
    $exporter = new ContentExporter($outputDir);
    $exporter->prepareOutputDir();
    foreach ($items as $item) {
      $exporter->export($item);
    }

    // Run Pagefind binary.
    $resolver = new PagefindBinary(
      configuredPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );

    $binary = $resolver->resolve();
    if ($binary === NULL) {
      $this->messenger()->addError($this->t('Pagefind binary not available. Use the PHP indexer or install Pagefind.'));
      return;
    }

    // PagefindBuilder validates the binary against an allowlist and runs it
    // through Symfony Process with a timeout — never shell out directly.
    $result = $this->pagefindBuilder->build($binary, $outputDir, $outputDir . '/pagefind');

    if (!$result['success']) {
      $this->messenger()->addError($this->t('Pagefind build failed: @output', [
        '@output' => $result['error'] ?? $result['output'],
      ]));
      return;
    }

    // Increment generation counter.
    $generation = $this->state->get('scolta.generation', 0);
    $this->state->set('scolta.generation', $generation + 1);

    $this->cacheTagsInvalidator->invalidateTags(['scolta_search_index']);
    // Store in State so the notice persists across page loads until dismissed.
    $this->state->set('scolta.rebuild_notice', ScoltaBatchOperations::buildNoticeData(
      'ok',
      (string) $this->t('Search index rebuilt successfully (binary).')
    ));
  }

  /**
   * Parse a multi-line "key|value" textarea into an associative array.
   *
   * Each line should be in "field_name|Description text" format. Lines that
   * do not contain a pipe character are silently skipped.
   *
   * @param string $raw
   *   The raw textarea value.
   *
   * @return array<string, string>
   *   Associative array of field name → description.
   */
  protected function parseKeyValueLines(string $raw): array {
    $result = [];
    foreach (explode("\n", $raw) as $line) {
      $line = trim($line);
      if ($line === '' || !str_contains($line, '|')) {
        continue;
      }
      [$key, $value] = explode('|', $line, 2);
      $key = trim($key);
      $value = trim($value);
      if ($key !== '' && $value !== '') {
        $result[$key] = $value;
      }
    }
    return $result;
  }

}
