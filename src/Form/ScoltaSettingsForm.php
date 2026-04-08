<?php

declare(strict_types=1);

namespace Drupal\scolta\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\scolta\Service\PagefindBuilder;
use Drupal\scolta\Service\ScoltaAiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scolta configuration form.
 *
 * Provides a comprehensive settings interface organized into sections:
 * AI, Content, Scoring, Display, Cache, Custom Prompts, and Status.
 */
class ScoltaSettingsForm extends ConfigFormBase {

  protected ScoltaAiService $aiService;
  protected PagefindBuilder $pagefindBuilder;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    ScoltaAiService $aiService,
    PagefindBuilder $pagefindBuilder,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
    $this->aiService = $aiService;
    $this->pagefindBuilder = $pagefindBuilder;
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
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI',
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
      '#description' => $this->t('Override the default AI prompts. Leave blank to use built-in defaults. Supports {SITE_NAME} and {SITE_DESCRIPTION} placeholders.'),
    ];

    $form['prompts']['prompt_expand_query'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Expand query prompt'),
      '#default_value' => $config->get('prompt_expand_query') ?? '',
      '#placeholder' => $this->getDefaultPrompt('expand_query'),
      '#rows' => 6,
      '#description' => $this->t('Custom system prompt for query expansion. Leave blank for default.'),
    ];

    $form['prompts']['prompt_summarize'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Summarize prompt'),
      '#default_value' => $config->get('prompt_summarize') ?? '',
      '#placeholder' => $this->getDefaultPrompt('summarize'),
      '#rows' => 6,
      '#description' => $this->t('Custom system prompt for result summarization. Leave blank for default.'),
    ];

    $form['prompts']['prompt_follow_up'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Follow-up prompt'),
      '#default_value' => $config->get('prompt_follow_up') ?? '',
      '#placeholder' => $this->getDefaultPrompt('follow_up'),
      '#rows' => 6,
      '#description' => $this->t('Custom system prompt for follow-up conversations. Leave blank for default.'),
    ];

    // ── Status Section (read-only) ──
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Status'),
      '#open' => FALSE,
    ];

    $form['status']['info'] = $this->buildStatusInfo();

    return parent::buildForm($form, $form_state);
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
        $message = $this->t('API key configured via settings.php ($settings[\'scolta.api_key\']).');
        $class = 'color--success';
        break;

      default:
        $message = $this->t('No API key configured. Set the SCOLTA_API_KEY environment variable or add $settings[\'scolta.api_key\'] to settings.php.');
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
    $binary = $config->get('pagefind.binary') ?? 'pagefind';
    $binaryCheck = $this->pagefindBuilder->checkBinary($binary);
    if ($binaryCheck['available']) {
      $items[] = $this->t('Pagefind binary: Found (@version)', [
        '@version' => $binaryCheck['version'] ?? 'unknown',
      ]);
    }
    else {
      $items[] = $this->t('Pagefind binary: Not found at "@path". Install via npm or use drush scolta:download-pagefind.', [
        '@path' => $binary,
      ]);
    }

    // Pagefind index status.
    $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    if (str_contains($outputDir, '://')) {
      try {
        /** @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $swm */
        $swm = \Drupal::service('stream_wrapper_manager');
        $resolvedDir = $swm->getViaUri($outputDir)->realpath() ?: $outputDir;
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
      $indexes = \Drupal::entityTypeManager()
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
        $allIndexes = \Drupal::entityTypeManager()
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
   * Get the default prompt template for use as a placeholder.
   *
   * Returns the raw template with {SITE_NAME} and {SITE_DESCRIPTION}
   * placeholders intact. Returns empty string if WASM is unavailable.
   */
  protected function getDefaultPrompt(string $name): string {
    try {
      return \Tag1\Scolta\Prompt\DefaultPrompts::getTemplate($name);
    }
    catch (\Throwable $e) {
      return '';
    }
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
      ->set('max_follow_ups', (int) $form_state->getValue('max_follow_ups'))
      // Content settings.
      ->set('site_name', $form_state->getValue('site_name'))
      ->set('site_description', $form_state->getValue('site_description'))
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
      ->set('prompt_expand_query', $form_state->getValue('prompt_expand_query'))
      ->set('prompt_summarize', $form_state->getValue('prompt_summarize'))
      ->set('prompt_follow_up', $form_state->getValue('prompt_follow_up'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
