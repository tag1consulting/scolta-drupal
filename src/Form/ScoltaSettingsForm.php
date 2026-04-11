<?php

declare(strict_types=1);

namespace Drupal\scolta\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Batch\ScoltaBatchOperations;
use Drupal\scolta\Service\PagefindBuilder;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Prompt\DefaultPrompts;

/**
 * Scolta configuration form.
 *
 * Provides a comprehensive settings interface organized into sections:
 * AI, Content, Scoring, Display, Cache, Custom Prompts, and Status.
 */
class ScoltaSettingsForm extends ConfigFormBase {

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
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    ScoltaAiService $aiService,
    PagefindBuilder $pagefindBuilder,
    StreamWrapperManagerInterface $streamWrapperManager,
    EntityTypeManagerInterface $entityTypeManager,
    StateInterface $state,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
    $this->aiService = $aiService;
    $this->pagefindBuilder = $pagefindBuilder;
    $this->streamWrapperManager = $streamWrapperManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->state = $state;
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

    $form['ai']['ai_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Provider'),
      '#options' => [
        'anthropic' => $this->t('Anthropic (Claude)'),
        'openai' => $this->t('OpenAI'),
      ],
      '#default_value' => $config->get('ai_provider') ?? 'anthropic',
    ];

    $form['ai']['ai_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AI Model'),
      '#default_value' => $config->get('ai_model') ?? 'claude-sonnet-4-5-20250929',
      '#description' => $this->t('Model identifier (e.g., claude-sonnet-4-5-20250929, gpt-4o).'),
    ];

    $form['ai']['ai_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AI Base URL'),
      '#default_value' => $config->get('ai_base_url') ?? '',
      '#description' => $this->t('Override the default API URL. Leave blank to use provider defaults.'),
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
      '#description' => $this->t('Comma-separated language codes (e.g., en, es, fr). When multiple languages are configured, AI responses will match the language of the user\'s query.'),
    ];

    $form['ai']['max_follow_ups'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum follow-up questions'),
      '#default_value' => $config->get('max_follow_ups') ?? 3,
      '#min' => 0,
      '#max' => 20,
      '#description' => $this->t('Maximum number of follow-up questions per search session.'),
    ];

    $form['ai']['api_key_status'] = $this->buildApiKeyStatus();

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
      '#description' => $this->t('Brief description used in AI prompts (e.g., "corporate website", "health system websites").'),
    ];

    $form['content']['indexer'] = [
      '#type' => 'select',
      '#title' => $this->t('Indexer mode'),
      '#options' => [
        'auto' => $this->t('Auto (use binary if available, otherwise PHP)'),
        'php' => $this->t('PHP (in-memory, no Pagefind binary needed)'),
        'binary' => $this->t('Binary (requires Pagefind CLI)'),
      ],
      '#default_value' => $config->get('indexer') ?? 'auto',
      '#description' => $this->t('How scolta:build creates the search index. Can be overridden with --indexer on the CLI.'),
    ];

    // ── Scoring Section ──
    $form['scoring'] = [
      '#type' => 'details',
      '#title' => $this->t('Scoring'),
      '#open' => FALSE,
    ];

    $form['scoring']['title_match_boost'] = [
      '#type' => 'number',
      '#title' => $this->t('Title match boost'),
      '#default_value' => $config->get('scoring.title_match_boost') ?? 1.0,
      '#step' => 0.1,
      '#min' => 0,
      '#description' => $this->t('Boost factor for title matches.'),
    ];

    $form['scoring']['title_all_terms_multiplier'] = [
      '#type' => 'number',
      '#title' => $this->t('Title all terms multiplier'),
      '#default_value' => $config->get('scoring.title_all_terms_multiplier') ?? 1.5,
      '#step' => 0.1,
      '#min' => 0,
      '#description' => $this->t('Extra multiplier when all search terms appear in the title.'),
    ];

    $form['scoring']['content_match_boost'] = [
      '#type' => 'number',
      '#title' => $this->t('Content match boost'),
      '#default_value' => $config->get('scoring.content_match_boost') ?? 0.4,
      '#step' => 0.1,
      '#min' => 0,
      '#description' => $this->t('Boost factor for content body matches.'),
    ];

    $form['scoring']['recency_boost_max'] = [
      '#type' => 'number',
      '#title' => $this->t('Recency boost maximum'),
      '#default_value' => $config->get('scoring.recency_boost_max') ?? 0.5,
      '#step' => 0.1,
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
      '#step' => 0.1,
      '#min' => 0,
      '#description' => $this->t('Maximum recency penalty for old content.'),
    ];

    $form['scoring']['expand_primary_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Expanded term primary weight'),
      '#default_value' => $config->get('scoring.expand_primary_weight') ?? 0.7,
      '#step' => 0.1,
      '#min' => 0,
      '#max' => 1,
      '#description' => $this->t('Weight given to the original query vs. expanded terms (0-1).'),
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
      '#default_value' => $config->get('display.ai_summary_max_chars') ?? 2000,
      '#min' => 100,
      '#max' => 10000,
      '#description' => $this->t('Maximum characters of context sent to AI for summarization.'),
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

    $form['actions']['rebuild_index'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rebuild Index'),
      '#name' => 'rebuild_index',
      '#submit' => ['::rebuildSubmit'],
      '#weight' => 10,
    ];

    return $form;
  }

  /**
   * Build the API key status display element.
   */
  protected function buildApiKeyStatus(): array {
    $source = $this->aiService->getApiKeySource();

    switch ($source) {
      case 'env':
        $message = $this->t('API key configured via SCOLTA_API_KEY environment variable.');
        $class = 'color--success';
        break;

      case 'settings':
        $message = $this->t("API key configured via settings.php (\$settings['scolta.api_key']).");
        $class = 'color--success';
        break;

      default:
        $message = $this->t("No API key configured. Set the SCOLTA_API_KEY environment variable or add \$settings['scolta.api_key'] to settings.php.");
        $class = 'color--warning';
        break;
    }

    return [
      '#type' => 'item',
      '#title' => $this->t('API Key Status'),
      '#markup' => '<span class="' . $class . '">' . $message . '</span>',
    ];
  }

  /**
   * Build the status information display.
   */
  protected function buildStatusInfo(): array {
    $items = [];

    // AI provider status.
    if ($this->aiService->hasDrupalAiModule()) {
      $items[] = $this->t('AI provider: Drupal AI module detected — requests will route through ai.provider service.');
    }
    else {
      $items[] = $this->t('AI provider: Built-in AiClient (direct HTTP). Install the AI module (ai:ai) for enhanced provider management.');
    }

    // Pagefind binary status.
    $config = $this->config('scolta.settings');
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('scolta.settings')
      // AI settings.
      ->set('ai_provider', $form_state->getValue('ai_provider'))
      ->set('ai_model', $form_state->getValue('ai_model'))
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
      ->set('indexer', $form_state->getValue('indexer'))
      // Scoring settings.
      ->set('scoring.title_match_boost', (float) $form_state->getValue('title_match_boost'))
      ->set('scoring.title_all_terms_multiplier', (float) $form_state->getValue('title_all_terms_multiplier'))
      ->set('scoring.content_match_boost', (float) $form_state->getValue('content_match_boost'))
      ->set('scoring.recency_boost_max', (float) $form_state->getValue('recency_boost_max'))
      ->set('scoring.recency_half_life_days', (int) $form_state->getValue('recency_half_life_days'))
      ->set('scoring.recency_penalty_after_days', (int) $form_state->getValue('recency_penalty_after_days'))
      ->set('scoring.recency_max_penalty', (float) $form_state->getValue('recency_max_penalty'))
      ->set('scoring.expand_primary_weight', (float) $form_state->getValue('expand_primary_weight'))
      // Display settings.
      ->set('display.excerpt_length', (int) $form_state->getValue('excerpt_length'))
      ->set('display.results_per_page', (int) $form_state->getValue('results_per_page'))
      ->set('display.max_pagefind_results', (int) $form_state->getValue('max_pagefind_results'))
      ->set('display.ai_summary_top_n', (int) $form_state->getValue('ai_summary_top_n'))
      ->set('display.ai_summary_max_chars', (int) $form_state->getValue('ai_summary_max_chars'))
      // Cache.
      ->set('cache_ttl', (int) $form_state->getValue('cache_ttl'))
      // Custom prompts.
      ->set('prompt_expand_query', $this->normalizePromptValue($form_state->getValue('prompt_expand_query') ?? '', 'expand_query'))
      ->set('prompt_summarize', $this->normalizePromptValue($form_state->getValue('prompt_summarize') ?? '', 'summarize'))
      ->set('prompt_follow_up', $this->normalizePromptValue($form_state->getValue('prompt_follow_up') ?? '', 'follow_up'))
      ->save();

    parent::submitForm($form, $form_state);
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
    $siteName = $config->get('site_name') ?: '';

    // Gather content items from published nodes.
    $items = $this->gatherContentItems($siteName);

    if (empty($items)) {
      $this->messenger()->addWarning($this->t('No content found to index.'));
      return;
    }

    // Filter through ContentExporter.
    $outputDir = $this->resolveOutputDir($config);
    $exporter = new ContentExporter($outputDir);
    $filteredItems = $exporter->exportToItems($items);

    if (empty($filteredItems)) {
      $this->messenger()->addWarning($this->t('No items passed content filter.'));
      return;
    }

    // Resolve indexer mode.
    $indexerMode = $config->get('indexer') ?: 'auto';
    if ($indexerMode === 'auto') {
      $indexerMode = $this->resolveAutoIndexer($config);
    }

    if ($indexerMode === 'php') {
      $this->rebuildWithBatch($filteredItems, $config);
    }
    else {
      $this->rebuildWithBinary($filteredItems, $config);
    }
  }

  /**
   * Gather content items from published node entities.
   *
   * @param string $siteName
   *   The site name for metadata.
   *
   * @return \Tag1\Scolta\Export\ContentItem[]
   *   Array of content items.
   */
  protected function gatherContentItems(string $siteName): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $entities = $storage->loadMultiple($ids);
    $items = [];

    foreach ($entities as $entity) {
      if (!$entity instanceof FieldableEntityInterface) {
        continue;
      }

      // Extract body content -- try common field names.
      $body = '';
      foreach (['body', 'field_body', 'field_content'] as $field) {
        if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
          $body = $entity->get($field)->value;
          break;
        }
      }

      if (empty($body)) {
        continue;
      }

      $changedTime = $entity instanceof EntityChangedInterface
        ? $entity->getChangedTime()
        : (int) ($entity->get('changed')->value ?? 0);

      $items[] = new ContentItem(
        id: (string) $entity->id(),
        title: $entity->label() ?: 'Untitled',
        bodyHtml: $body,
        url: $entity->toUrl()->setAbsolute(TRUE)->toString(),
        date: date('Y-m-d', $changedTime),
        siteName: $siteName,
      );
    }

    return $items;
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
    $stateDir = $config->get('pagefind.build_dir') ?? 'private://scolta-build';
    if (str_contains($stateDir, '://')) {
      try {
        $resolved = $this->streamWrapperManager
          ->getViaUri($stateDir)->realpath() ?: $stateDir;
        return $resolved;
      }
      catch (\Exception $e) {
        return $stateDir;
      }
    }
    return $stateDir;
  }

  /**
   * Resolve 'auto' indexer mode based on binary availability.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   *
   * @return string
   *   'binary' if the Pagefind binary is available, 'php' otherwise.
   */
  protected function resolveAutoIndexer($config): string {
    $resolver = new PagefindBinary(
      configuredPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );

    $binary = $resolver->resolve();
    return $binary !== NULL ? 'binary' : 'php';
  }

  /**
   * Rebuild using Batch API with the PHP indexer.
   *
   * @param \Tag1\Scolta\Export\ContentItem[] $items
   *   The filtered content items.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   */
  protected function rebuildWithBatch(array $items, $config): void {
    $stateDir = $this->resolveStateDir($config);
    $outputDir = $this->resolveOutputDir($config);
    $language = $config->get('ai_languages')[0] ?? 'en';

    // Ensure directories exist.
    if (!is_dir($stateDir)) {
      mkdir($stateDir, 0755, TRUE);
    }
    if (!is_dir($outputDir)) {
      mkdir($outputDir, 0755, TRUE);
    }

    $batchConfig = [
      'state_dir' => $stateDir,
      'output_dir' => $outputDir,
      'hmac_secret' => NULL,
      'language' => $language,
    ];

    $chunkSize = 100;
    $chunks = array_chunk($items, $chunkSize);
    $totalPages = count($items);

    $operations = [];
    foreach ($chunks as $idx => $chunk) {
      $operations[] = [
        [ScoltaBatchOperations::class, 'processChunk'],
        [$idx, $chunk, $totalPages, $batchConfig],
      ];
    }

    // Add finalize operation.
    $operations[] = [
      [ScoltaBatchOperations::class, 'finalize'],
      [$batchConfig],
    ];

    $batch = [
      'title' => t('Rebuilding search index...'),
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
    $language = $config->get('ai_languages')[0] ?? 'en';

    // Ensure directories exist.
    if (!is_dir($stateDir)) {
      mkdir($stateDir, 0755, TRUE);
    }
    if (!is_dir($outputDir)) {
      mkdir($outputDir, 0755, TRUE);
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

    $cmd = $binary
      . ' --site ' . escapeshellarg($outputDir)
      . ' --output-path ' . escapeshellarg($outputDir)
      . ' 2>&1';
    $output = [];
    $exitCode = NULL;
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
      $this->messenger()->addError($this->t('Pagefind build failed: @output', [
        '@output' => implode("\n", $output),
      ]));
      return;
    }

    // Increment generation counter.
    $generation = $this->state->get('scolta.generation', 0);
    $this->state->set('scolta.generation', $generation + 1);

    $this->messenger()->addMessage($this->t('Search index rebuilt successfully (binary).'));
  }

}
