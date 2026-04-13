<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\search_api\backend;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->exporter = $container->get('scolta.pagefind_exporter');
    $instance->builder = $container->get('scolta.pagefind_builder');
    $instance->streamWrapperManager = $container->get('stream_wrapper_manager');
    $instance->scoltaLogger = $container->get('logger.channel.scolta');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'build_dir' => 'private://scolta-build',
      'output_dir' => 'public://scolta-pagefind',
      'pagefind_binary' => 'pagefind',
      'auto_rebuild' => TRUE,
      'auto_rebuild_delay' => 300,
      'view_mode' => 'search_index',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['build_dir'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Build directory'),
      '#description' => $this->t('Where exported HTML files are written before Pagefind indexes them. Supports stream wrappers (private://, public://) or absolute paths.'),
      '#default_value' => $this->configuration['build_dir'],
      '#required' => TRUE,
    ];

    $form['output_dir'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pagefind output directory'),
      '#description' => $this->t('Where the Pagefind index (_pagefind/) is written. Must be web-accessible. Supports stream wrappers or absolute paths.'),
      '#default_value' => $this->configuration['output_dir'],
      '#required' => TRUE,
    ];

    $form['pagefind_binary'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pagefind binary path'),
      '#description' => $this->t('Path to the pagefind binary. Use "pagefind" if installed globally, "npx pagefind" for npm, or an absolute path.'),
      '#default_value' => $this->configuration['pagefind_binary'],
      '#required' => TRUE,
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
    // Validate pagefind binary is reachable.
    $binary = $form_state->getValue('pagefind_binary');
    if ($binary && !str_contains($binary, 'npx')) {
      // Only check direct binary paths, not npx commands.
      $result = $this->builder->checkBinary($binary);
      if (!$result['available']) {
        $form_state->setErrorByName('pagefind_binary', $this->t('Pagefind binary not found at @path. Install via npm (npm install -g pagefind) or provide the correct path.', ['@path' => $binary]));
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

    if (!empty($indexed) && $this->configuration['auto_rebuild']) {
      $this->triggerRebuild();
    }

    return $indexed;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids): void {
    $buildDir = $this->getResolvedBuildDir();

    foreach ($item_ids as $id) {
      $this->exporter->deleteItem($id, $buildDir);
    }

    if ($this->configuration['auto_rebuild']) {
      $this->triggerRebuild();
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
    $buildDir = $this->getResolvedBuildDir();
    $outputDir = $this->getResolvedOutputDir();
    $binary = $this->configuration['pagefind_binary'];

    $result = $this->builder->build($binary, $buildDir, $outputDir);

    if ($result['success']) {
      $this->scoltaLogger->info('Pagefind index rebuilt: @files files, @size.', [
        '@files' => $result['file_count'] ?? '?',
        '@size' => $result['index_size'] ?? '?',
      ]);
      Cache::invalidateTags(['scolta:expand']);
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
   */
  protected function getResolvedBuildDir(): string {
    $dir = $this->configuration['build_dir'];
    if (str_contains($dir, '://')) {
      $dir = $this->streamWrapperManager->getViaUri($dir)->realpath() ?: $dir;
    }
    return $dir;
  }

  /**
   * Resolve the output directory path (handle stream wrappers).
   */
  protected function getResolvedOutputDir(): string {
    $dir = $this->configuration['output_dir'];
    if (str_contains($dir, '://')) {
      $dir = $this->streamWrapperManager->getViaUri($dir)->realpath() ?: $dir;
    }
    return $dir;
  }

}
