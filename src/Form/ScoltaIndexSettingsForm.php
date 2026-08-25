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
use Drupal\scolta\Batch\ScoltaBatchOperations;
use Drupal\scolta\Service\PagefindBuilder;
use Drupal\scolta\Service\ScoltaContentGatherer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentExporter;

/**
 * Index build settings: what goes into the index, and how it is built.
 *
 * The query-time half of Scolta's configuration — scoring, display, the AI
 * tier — belongs to scolta_ui and is edited at /admin/config/search/scolta.
 * The two forms are deliberately separate objects on separate config, so a
 * site that installs only one module still has a complete settings screen.
 *
 * The dimension NAMES live here because the index is built around them; their
 * human descriptions live with the frontend, which is what reads them.
 */
class ScoltaIndexSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly PagefindBuilder $pagefindBuilder,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly FileSystemInterface $fileSystem,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly ScoltaContentGatherer $contentGatherer,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('scolta.pagefind_builder'),
      $container->get('stream_wrapper_manager'),
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('file_system'),
      $container->get('cache_tags.invalidator'),
      $container->get('scolta.content_gatherer'),
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
    return 'scolta_index_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('scolta.settings');

    $form['content'] = [
      '#type' => 'details',
      '#title' => $this->t('Index contents'),
      '#open' => TRUE,
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

    $form['content']['filter_fields'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filter fields'),
      '#default_value' => implode(', ', $config->get('filter_fields') ?? []),
      '#description' => $this->t('Comma-separated list of filter dimension names (e.g., "topic, era, region"). Must match the filter names used in data-pagefind-filter attributes.'),
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

    $form['status'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Index status'),
      '#items' => $this->buildStatusItems($config),
    ];

    $form['actions']['rebuild'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rebuild index now'),
      '#submit' => ['::rebuildSubmit'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Reject key|value lines without a pipe instead of silently dropping them.
    // The guard came across with the two textareas it is about; the frontend
    // form keeps the identical guard for the two description fields it kept.
    $pipeFields = [
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
    $this->config('scolta.settings')
      ->set('body_fields', array_values(array_filter(array_map(
        'trim',
        explode(',', $form_state->getValue('body_fields') ?? '')
      ))))
      ->set('sortable_fields', array_values(array_filter(array_map(
        'trim',
        explode(',', $form_state->getValue('sortable_fields') ?? '')
      ))))
      ->set('filter_fields', array_values(array_filter(array_map(
        'trim',
        explode(',', $form_state->getValue('filter_fields') ?? '')
      ))))
      ->set('field_mappings.sortable', $this->parseKeyValueLines($form_state->getValue('field_mapping_sortable') ?? ''))
      ->set('field_mappings.filters', $this->parseKeyValueLines($form_state->getValue('field_mapping_filters') ?? ''))
      ->set('indexer', $form_state->getValue('indexer'))
      ->set('memory_budget.profile', $form_state->getValue('memory_budget_profile') ?? 'conservative')
      ->set('memory_budget.custom_bytes', NULL)
      ->set('memory_budget.chunk_size', ($form_state->getValue('chunk_size') !== '' && $form_state->getValue('chunk_size') !== NULL) ? (int) $form_state->getValue('chunk_size') : NULL)
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
    // site_name and ai_languages are dual-lifecycle keys: the frontend owns
    // and edits them, but a build bakes both into the index — the site name
    // onto every content item, the language into the Pagefind index itself.
    // Read from scolta_ui.settings by config name, the same way IndexOrigin
    // reads pagefind.output_dir the other way: Drupal config is global, so
    // this works with or without that module installed, and a backend-only
    // site falls back to the Drupal site name and English.
    $siteName = $this->config('scolta_ui.settings')->get('site_name')
      ?: ($this->config('system.site')->get('name') ?? '');

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
    $language = ($this->config('scolta_ui.settings')->get('ai_languages') ?? [])[0] ?? 'en';

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

  /**
   * Index build status: the binary, the build directory and the built index.
   *
   * Moved here with the rebuild control it describes. The frontend has no
   * business reaching the backend's services to report on a build it does
   * not run, and on a consumer site there is no local build to report.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The scolta.settings config.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Status lines, ready for an item list.
   */
  protected function buildStatusItems($config): array {
    $items = [];

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
      $items[] = $this->t('Pagefind index: Built (@count fragments, last built @date)', [
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

    return $items;
  }

}
