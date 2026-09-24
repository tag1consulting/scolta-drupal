<?php

declare(strict_types=1);

namespace Drupal\scolta\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Batch\ScoltaBatchOperations;
use Drupal\scolta\Service\IndexBuildRunner;
use Drupal\scolta\Service\IndexLocator;
use Drupal\scolta\Service\ScoltaContentGatherer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\Config\MemoryBudgetConfig;

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

  /**
   * Constructs a ScoltaIndexSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\scolta\Service\IndexLocator $indexLocator
   *   The index locator.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\scolta\Service\ScoltaContentGatherer $contentGatherer
   *   The content gatherer service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The entity type bundle info service.
   * @param \Drupal\scolta\Service\IndexBuildRunner $runner
   *   The build path, for the site name and language a build bakes in.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    protected IndexLocator $indexLocator,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
    protected FileSystemInterface $fileSystem,
    protected ScoltaContentGatherer $contentGatherer,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected IndexBuildRunner $runner,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('scolta.index_locator'),
      $container->get('stream_wrapper_manager'),
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('file_system'),
      $container->get('scolta.content_gatherer'),
      $container->get('entity_type.bundle.info'),
      $container->get('scolta.index_build_runner'),
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

    // ── Entity types ──
    // Maps one-to-one onto scolta.settings: entity_types (type => bundles,
    // an empty bundle list meaning every bundle), so entityTypes() needs no
    // form-specific decoding.
    $configuredTypes = $this->contentGatherer->entityTypes();
    $form['content']['entity_types'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Entity types to index'),
      '#description' => $this->t('Content entity types the index covers. Under a checked type, leave every bundle unchecked to index all of its bundles, including ones added later. Changing this list requires a full rebuild.'),
      '#tree' => TRUE,
    ];
    foreach ($this->indexableEntityTypes() as $typeId => $definition) {
      $form['content']['entity_types'][$typeId]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Index @label', ['@label' => $definition->getLabel()]),
        '#default_value' => array_key_exists($typeId, $configuredTypes),
      ];
      if (!$definition->getKey('bundle')) {
        continue;
      }
      $bundleOptions = [];
      foreach ($this->bundleInfo->getBundleInfo($typeId) as $bundle => $info) {
        $bundleOptions[$bundle] = $info['label'];
      }
      $form['content']['entity_types'][$typeId]['bundles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('@label bundles', ['@label' => $definition->getLabel()]),
        '#options' => $bundleOptions,
        '#default_value' => $configuredTypes[$typeId] ?? [],
        '#states' => [
          'visible' => [
            ':input[name="entity_types[' . $typeId . '][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

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
      '#description' => $this->t('Comma-separated list of fields available for sorting (e.g., "date, price"). When non-empty, the AI can detect sort intent and return a sort hint. Describe them for the AI in the search settings.'),
    ];

    $form['content']['filter_fields'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filter fields'),
      '#default_value' => implode(', ', $config->get('filter_fields') ?? []),
      '#description' => $this->t('Comma-separated list of filter dimension names (e.g., "topic, era, region"). Must match the filter names used in data-pagefind-filter attributes. Describe them for the AI in the search settings.'),
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

    $memoryBudgetConfig = MemoryBudgetConfig::load([
      'profile'      => $config->get('memory_budget.profile') ?? 'conservative',
      'custom_bytes' => $config->get('memory_budget.custom_bytes'),
      'chunk_size'   => $config->get('memory_budget.chunk_size'),
    ]);
    $form['content']['memory_budget'] = MemoryBudgetSettingsFieldSet::build($memoryBudgetConfig);

    // ── Status Section (read-only) ──
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Index status'),
      '#open' => TRUE,
    ];
    $form['status']['info'] = $this->buildStatusInfo();

    $form = parent::buildForm($form, $form_state);

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
   * Build the index status display: the build directory and the built index.
   *
   * Moved here with the rebuild control it describes. The frontend has no
   * business reaching the backend's services to report on a build it does
   * not run, and on a consumer site there is no local build to report.
   */
  protected function buildStatusInfo(): array {
    $items = [];
    $config = $this->config('scolta.settings');

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
    $location = $this->indexLocator->locate($this->resolveOutputDir($config));
    if ($location !== NULL) {
      $pageCount = $this->indexLocator->pageCount($location) ?? $this->indexLocator->countFragments($location);
      $mtime = filemtime($location['indexFile']);
      $items[] = $this->t('Search index: Built (@count pages, last built @date)', [
        '@count' => $pageCount,
        '@date' => $mtime ? date('Y-m-d H:i:s', $mtime) : 'unknown',
      ]);
    }
    else {
      $items[] = $this->t('Search index: Not built yet. Use Rebuild Index below or run drush scolta:build.');
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
   * Content entity types the indexer can render and link to.
   *
   * @return array<string, \Drupal\Core\Entity\ContentEntityTypeInterface>
   *   Definitions keyed by entity type ID, sorted by label.
   */
  private function indexableEntityTypes(): array {
    $types = array_filter(
      $this->entityTypeManager->getDefinitions(),
      static fn ($definition) => $definition instanceof ContentEntityTypeInterface
        && $definition->hasViewBuilderClass()
        && $definition->hasLinkTemplate('canonical')
    );
    uasort($types, static fn ($a, $b) => strcasecmp((string) $a->getLabel(), (string) $b->getLabel()));
    return $types;
  }

  /**
   * The entity_types config value the submitted form describes.
   *
   * A configured type the form does not list (its module is not installed,
   * or it is not renderable) is carried through unchanged rather than
   * silently dropped.
   *
   * @return array<string, string[]>
   *   Entity type ID => bundles (empty for all).
   */
  private function entityTypesFromForm(FormStateInterface $form_state): array {
    $types = array_diff_key($this->contentGatherer->entityTypes(), $this->indexableEntityTypes());
    foreach ((array) ($form_state->getValue('entity_types') ?? []) as $typeId => $values) {
      if (empty($values['enabled'])) {
        continue;
      }
      // Checkboxes submit checked options as key => key and unchecked as 0.
      $types[$typeId] = array_map('strval', array_keys(array_filter((array) ($values['bundles'] ?? []))));
    }
    return $types;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if ($this->entityTypesFromForm($form_state) === []) {
      $form_state->setErrorByName('entity_types', $this->t('Select at least one entity type to index.'));
    }

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
      ->set('entity_types', $this->entityTypesFromForm($form_state))
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
      ->set('memory_budget.profile', $form_state->getValue('memory_budget_profile') ?? 'conservative')
      ->set('memory_budget.custom_bytes', NULL)
      ->set('memory_budget.chunk_size', ($form_state->getValue('chunk_size') !== '' && $form_state->getValue('chunk_size') !== NULL) ? (int) $form_state->getValue('chunk_size') : NULL)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler for the "Rebuild Index" button.
   *
   * Queries the IDs of the configured entity types and indexes them through
   * the Batch API.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function rebuildSubmit(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('scolta.settings');

    // Clear any previous notice so a fresh notice_id is used after this
    // rebuild.
    $this->state->delete('scolta.rebuild_notice');

    // Only query entity IDs here. Entity loading and content filtering happen
    // inside each batch step so that no single web request has to load the
    // full corpus into memory. This prevents the "Index Now" button from
    // timing out on shared hosting at any corpus size.
    $idsByType = [];
    foreach ($this->contentGatherer->entityTypes() as $entityType => $bundles) {
      $definition = $this->entityTypeManager->getDefinition($entityType);
      $query = $this->entityTypeManager->getStorage($entityType)->getQuery()->accessCheck(FALSE);
      if ($definition->getKey('published')) {
        $query->condition($definition->getKey('published'), 1);
      }
      if ($bundles && $definition->getKey('bundle')) {
        $query->condition($definition->getKey('bundle'), $bundles, 'IN');
      }
      $ids = array_values($query->execute());
      if ($ids) {
        $idsByType[$entityType] = $ids;
      }
    }

    if (empty($idsByType)) {
      $this->messenger()->addWarning($this->t('No content found to index.'));
      return;
    }

    $this->rebuildWithBatch($idsByType, $config);
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
   * Rebuild using the Batch API.
   *
   * Accepts entity IDs rather than pre-loaded ContentItems so that no single
   * web request ever loads the full corpus. Entity loading, content extraction,
   * and filtering all happen inside each batch step.
   *
   * The site name and language baked into the index come from the build
   * runner, which reads scolta_ui.settings by config name and falls back to
   * the Drupal site name and English on a backend-only site.
   *
   * @param array<string, int[]> $idsByType
   *   Published entity IDs to index, keyed by entity type ID.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   */
  protected function rebuildWithBatch(array $idsByType, $config): void {
    $stateDir = $this->resolveStateDir($config);
    $outputDir = $this->resolveOutputDir($config);
    $siteName = $this->runner->siteName();
    $language = $this->runner->language();

    // Ensure directories exist.
    if (!is_dir($stateDir)) {
      $this->fileSystem->mkdir($stateDir, 0755, TRUE);
    }
    scolta_mark_state_format($stateDir);
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
    $totalCount = array_sum(array_map('count', $idsByType));

    $operations = [];
    $idx = 0;
    foreach ($idsByType as $entityType => $entityIds) {
      foreach (array_chunk($entityIds, $chunkSize) as $idChunk) {
        $operations[] = [
          [ScoltaBatchOperations::class, 'loadAndProcessChunk'],
          [$idx++, $entityType, $idChunk, $totalCount, $siteName, $batchConfig],
        ];
      }
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
   * Parse a multi-line "key|value" textarea into an associative array.
   *
   * Each line should be in "field_name|dimension" format. Lines that do not
   * contain a pipe character are silently skipped.
   *
   * @param string $raw
   *   The raw textarea value.
   *
   * @return array<string, string>
   *   Associative array of field name → dimension.
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
