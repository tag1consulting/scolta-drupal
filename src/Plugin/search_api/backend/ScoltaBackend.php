<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\search_api\backend;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Service\PagefindBuilder;
use Drupal\scolta\Service\PagefindExporter;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search API backend that indexes content for Pagefind.
 *
 * Pagefind is a client-side search engine — the browser loads a WASM binary
 * and a pre-built static index, and search happens entirely in JavaScript.
 * This backend handles the *indexing* side: rendering entities to HTML files
 * with Pagefind data attributes, then invoking the Pagefind CLI to build
 * the static index.
 *
 * The search() method returns empty results by design. The actual search UI
 * is a controller that serves a page with Pagefind JS attached. Sites that
 * need server-side search results should use Solr/DB backend alongside.
 *
 * @SearchApiBackend(
 *   id = "scolta_pagefind",
 *   label = @Translation("Scolta (Pagefind)"),
 *   description = @Translation("Client-side search powered by Pagefind. Indexes content as static HTML files with a WASM-based browser search engine. No server-side search infrastructure required.")
 * )
 */
class ScoltaBackend extends BackendPluginBase implements PluginFormInterface {

  /**
   * The Pagefind exporter service.
   *
   * @var \Drupal\scolta\Service\PagefindExporter
   */
  protected PagefindExporter $exporter;

  /**
   * The Pagefind builder service.
   *
   * @var \Drupal\scolta\Service\PagefindBuilder
   */
  protected PagefindBuilder $builder;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * The Scolta logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $scoltaLogger;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->exporter = $container->get('scolta.pagefind_exporter');
    $instance->builder = $container->get('scolta.pagefind_builder');
    $instance->streamWrapperManager = $container->get('stream_wrapper_manager');
    $instance->scoltaLogger = $container->get('logger.channel.scolta');
    $instance->queueFactory = $container->get('queue');
    $instance->state = $container->get('state');
    $instance->cacheTagsInvalidator = $container->get('cache_tags.invalidator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'build_dir' => 'public://scolta-build',
      'output_dir' => 'public://scolta-pagefind',
      'pagefind_binary' => 'pagefind',
      'auto_rebuild' => TRUE,
      'auto_rebuild_delay' => 300,
      'view_mode' => 'search_index',
      'indexer' => 'auto',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $buildDir = $this->configuration['build_dir'];
    $buildDirDescription = $this->t('Where exported HTML files are written before Pagefind indexes them. Supports stream wrappers (private://, public://) or absolute paths.');

    // Warn when the default private:// path is selected but private file system
    // is not configured. The backend will fall back to public://scolta-build.
    if (str_starts_with($buildDir, 'private://')) {
      $privateWrapper = $this->streamWrapperManager->getViaUri('private://');
      if (!$privateWrapper || $privateWrapper->realpath() === FALSE) {
        $buildDirDescription = $this->t('Private file system is not configured on this site. The build directory will use <code>public://scolta-build</code> instead. For better security, configure a private file path in settings.php.');
      }
    }

    $form['build_dir'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Build directory'),
      '#description' => $buildDirDescription,
      '#default_value' => $buildDir,
      '#required' => TRUE,
    ];

    $form['output_dir'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pagefind output directory'),
      '#description' => $this->t('Where the Pagefind index (_pagefind/) is written. Must be web-accessible. Supports stream wrappers or absolute paths.'),
      '#default_value' => $this->configuration['output_dir'],
      '#required' => TRUE,
    ];

    $form['indexer'] = [
      '#type' => 'select',
      '#title' => $this->t('Indexer mode'),
      '#description' => $this->t('How the search index is built after content is exported. <strong>Auto / PHP</strong> works on all hosting environments — no exec() or Node.js required. <strong>Binary</strong> invokes the Pagefind CLI; requires a binary installed on the server.'),
      '#options' => [
        'auto' => $this->t('Auto (PHP indexer)'),
        'php' => $this->t('PHP indexer'),
        'binary' => $this->t('Pagefind binary'),
      ],
      '#default_value' => $this->configuration['indexer'] ?? 'auto',
    ];

    $form['pagefind_binary'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pagefind binary path'),
      '#description' => $this->t('Path to the pagefind binary. Use "pagefind" if installed globally, "npx pagefind" for npm, or an absolute path. Only used when indexer mode is set to "Binary".'),
      '#default_value' => $this->configuration['pagefind_binary'],
      '#required' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[indexer]"]' => ['value' => 'binary'],
        ],
      ],
    ];

    $form['view_mode'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity view mode'),
      '#description' => $this->t('The view mode used to render entities for indexing. "search_index" is recommended (strips chrome, keeps content). "full" includes the full page rendering.'),
      '#default_value' => $this->configuration['view_mode'],
      '#required' => TRUE,
    ];

    $form['auto_rebuild'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-rebuild index after changes'),
      '#description' => $this->t('Run Pagefind CLI automatically after indexing. Disable for high-edit sites where you want to trigger builds manually via Drush.'),
      '#default_value' => $this->configuration['auto_rebuild'],
    ];

    $form['auto_rebuild_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Rebuild delay (seconds)'),
      '#description' => $this->t('Seconds to wait after the last content change before rebuilding. Minimum 60. Default 300. Higher values batch more changes.'),
      '#default_value' => $this->configuration['auto_rebuild_delay'] ?? 300,
      '#min' => 60,
      '#max' => 3600,
      '#step' => 60,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[auto_rebuild]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $indexer = $form_state->getValue('indexer') ?: 'auto';

    // Validate pagefind binary only when binary indexer mode is selected.
    if ($indexer === 'binary') {
      $binary = $form_state->getValue('pagefind_binary');
      if (empty($binary)) {
        $form_state->setErrorByName('pagefind_binary', $this->t('Pagefind binary path is required when using binary indexer mode.'));
      }
      elseif (!str_contains($binary, 'npx')) {
        // Only check direct binary paths, not npx commands.
        $result = $this->builder->checkBinary($binary);
        if (!$result['available']) {
          $form_state->setErrorByName('pagefind_binary', $this->t('Pagefind binary not found at @path. Install via npm (npm install -g pagefind) or provide the correct path.', ['@path' => $binary]));
        }
      }
    }

    // Clamp auto_rebuild_delay to 60–3600.
    $delay = (int) $form_state->getValue('auto_rebuild_delay');
    if ($delay < 60 || $delay > 3600) {
      $form_state->setValue('auto_rebuild_delay', max(60, min(3600, $delay)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['build_dir'] = $form_state->getValue('build_dir');
    $this->configuration['output_dir'] = $form_state->getValue('output_dir');
    $this->configuration['pagefind_binary'] = $form_state->getValue('pagefind_binary');
    $this->configuration['view_mode'] = $form_state->getValue('view_mode');
    $this->configuration['auto_rebuild'] = (bool) $form_state->getValue('auto_rebuild');
    $this->configuration['auto_rebuild_delay'] = max(60, min(3600, (int) $form_state->getValue('auto_rebuild_delay')));
    $this->configuration['indexer'] = $form_state->getValue('indexer') ?: 'auto';
  }

  /**
   * {@inheritdoc}
   *
   * Receives processed items from Search API's pipeline and writes each
   * as an HTML file with Pagefind data attributes.
   */
  public function indexItems(IndexInterface $index, array $items): array {
    $buildDir = $this->getResolvedBuildDir();
    $viewMode = $this->configuration['view_mode'];
    $indexed = [];

    foreach ($items as $id => $item) {
      try {
        $this->exporter->exportItem($item, $buildDir, $viewMode);
        $indexed[] = $id;
      }
      catch (\Exception $e) {
        $this->scoltaLogger->error('Failed to export item @id: @message', [
          '@id' => $id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if (!empty($indexed)) {
      // Record where the batch landed before anything else can fail. Without
      // the manifest the nested export paths cannot be recovered from an item
      // ID, and deleting these items later would leave their HTML in place.
      $this->writeExportManifest($buildDir);
    }

    if (!empty($indexed) && $this->configuration['auto_rebuild']) {
      if (!$this->triggerRebuild()) {
        throw new \RuntimeException('Pagefind binary build failed after indexing items. The HTML files were exported but the search index was not updated. Check the Scolta logs for details.');
      }
    }

    return $indexed;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids): void {
    $buildDir = $this->getResolvedBuildDir();
    $missing = [];

    foreach ($item_ids as $id) {
      if (!$this->exporter->deleteItem($id, $buildDir)) {
        $missing[] = $id;
      }
    }

    if (!empty($item_ids)) {
      $this->writeExportManifest($buildDir);
    }

    if (!empty($missing)) {
      // Two states produce a miss and the delete path cannot tell them
      // apart: the item was never exported (exportItem() skips entities that
      // render to empty content, and indexItems() still reports them indexed
      // so Search API does not retry them forever), or it was exported before
      // the manifest existed and its HTML is still on disk unrecorded.
      $this->scoltaLogger->warning('No exported file found for @missing of @total item(s) removed from index @index. Either the item was never exported because it had no renderable content at index time, or it was exported before the export manifest in @dir existed — in which case its HTML is still there for Pagefind to index until the index is rebuilt. Item IDs: @ids', [
        '@missing' => count($missing),
        '@total' => count($item_ids),
        '@index' => $index->id(),
        '@dir' => $buildDir,
        '@ids' => implode(', ', $missing),
      ]);
    }

    if ($this->configuration['auto_rebuild']) {
      $this->triggerRebuild();
    }
  }

  /**
   * Persist the exporter's item ID → export path map, logging any failure.
   *
   * A manifest that cannot be written is not worth failing an index or delete
   * operation over — the HTML is already correct on disk — but it does leave
   * later deletes unable to find their files, so it is logged as an error.
   *
   * @param string $buildDir
   *   The resolved build directory.
   */
  protected function writeExportManifest(string $buildDir): void {
    try {
      $this->exporter->writeManifest($buildDir);
    }
    catch (\Exception $e) {
      $this->scoltaLogger->error('Failed to write the export manifest in @dir: @message. Deleting indexed items will not remove their exported HTML until a manifest is written.', [
        '@dir' => $buildDir,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL): void {
    $buildDir = $this->getResolvedBuildDir();
    $this->exporter->deleteAll($buildDir, $datasource_id);

    if ($this->configuration['auto_rebuild']) {
      $this->triggerRebuild();
    }
  }

  /**
   * {@inheritdoc}
   *
   * Pagefind search is client-side. This method intentionally returns no
   * results. The search UI is served by Scolta's search page controller
   * which loads the Pagefind JS/WASM bundle in the browser.
   */
  public function search(QueryInterface $query): void {
    $this->scoltaLogger->notice('ScoltaBackend::search() called. Pagefind search is client-side — use the Scolta search page instead of Views or programmatic Search API queries.');
  }

  /**
   * Trigger a Pagefind index rebuild.
   */
  public function triggerRebuild(): bool {
    $indexer = $this->configuration['indexer'] ?? 'auto';

    if ($indexer !== 'binary') {
      // PHP pipeline (auto or php): queue a background rebuild via cron.
      $this->queueFactory->get('scolta_rebuild')->createItem(['triggered_by' => 'search_api_indexing']);
      $this->scoltaLogger->info('Queued background PHP index rebuild after Search API indexing (indexer mode: @mode).', [
        '@mode' => $indexer,
      ]);
      return TRUE;
    }

    $buildDir = $this->getResolvedBuildDir();
    $outputDir = $this->getResolvedOutputDir();
    $binary = $this->configuration['pagefind_binary'];

    $result = $this->builder->build($binary, $buildDir, $outputDir);

    if ($result['success']) {
      $this->scoltaLogger->info('Pagefind index rebuilt: @files files, @size.', [
        '@files' => $result['file_count'] ?? '?',
        '@size' => $result['index_size'] ?? '?',
      ]);
      // Bump the generation counter so cached AI responses (keyed on the
      // generation) are invalidated for the fresh index, and flag render
      // caches that depend on the index.
      $generation = $this->state->get('scolta.generation', 0);
      $this->state->set('scolta.generation', $generation + 1);
      $this->cacheTagsInvalidator->invalidateTags(['scolta_search_index']);
    }
    else {
      $this->scoltaLogger->error('Pagefind build failed: @error', [
        '@error' => $result['error'] ?? 'Unknown error',
      ]);
    }

    return $result['success'];
  }

  /**
   * Resolve the build directory path (handle stream wrappers).
   *
   * Falls back to public://scolta-build when the configured directory uses
   * private:// and the private file system is not configured on this site.
   */
  protected function getResolvedBuildDir(): string {
    $dir = $this->configuration['build_dir'];
    if (str_contains($dir, '://')) {
      $wrapper = $this->streamWrapperManager->getViaUri($dir);
      $resolved = ($wrapper && ($realpath = $wrapper->realpath()) !== FALSE) ? $realpath : $dir;

      // When private:// is unavailable, fall back to public://scolta-build.
      if ($resolved === $dir && str_starts_with($dir, 'private://')) {
        $this->scoltaLogger->notice('Private file system not configured; using public://scolta-build for index storage.');
        $fallback = $this->resolvePublicFallbackDir('scolta-build');
        if ($fallback !== NULL) {
          return $fallback;
        }
      }

      return $resolved;
    }
    return $dir;
  }

  /**
   * Resolve a public:// subdirectory path even when it does not yet exist.
   */
  protected function resolvePublicFallbackDir(string $subdir): ?string {
    $publicWrapper = $this->streamWrapperManager->getViaUri('public://');
    if (!$publicWrapper) {
      return NULL;
    }
    $basePath = $publicWrapper->realpath();
    if ($basePath === FALSE) {
      return NULL;
    }
    return $basePath . '/' . $subdir;
  }

  /**
   * Resolve the output directory path (handle stream wrappers).
   */
  protected function getResolvedOutputDir(): string {
    $dir = $this->configuration['output_dir'];
    if (str_contains($dir, '://')) {
      $wrapper = $this->streamWrapperManager->getViaUri($dir);
      $dir = ($wrapper && ($realpath = $wrapper->realpath()) !== FALSE) ? $realpath : $dir;
    }
    return $dir;
  }

}
