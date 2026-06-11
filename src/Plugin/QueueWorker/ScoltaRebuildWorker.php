<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\QueueWorker;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Service\ScoltaContentGatherer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\IndexBuildOrchestrator;

/**
 * Queue worker for rebuilding the Scolta search index.
 *
 * Processes queued rebuild requests triggered by entity changes when
 * auto-rebuild is enabled. Runs the same streamed pipeline as
 * `drush scolta:build`: ScoltaContentGatherer (translations, text-format
 * rendering, field mappings, alter hook; 10 entities per load) →
 * ContentExporter::filterItems() → IndexBuildOrchestrator.
 *
 * Rebuilds are debounced: scolta.module records the last content change in
 * the scolta.rebuild_requested_at state key, and the worker suspends the
 * queue until the backend's auto_rebuild_delay has elapsed since that
 * change, so a burst of edits produces one build. After a successful build
 * the remaining queued duplicates are drained.
 *
 * @QueueWorker(
 *   id = "scolta_rebuild",
 *   title = @Translation("Scolta Index Rebuild"),
 *   cron = {"time" = 120}
 * )
 *
 * @since 1.0.0-rc1
 * @stability experimental
 */
class ScoltaRebuildWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Fallback debounce delay when no Scolta search_api server exists.
   */
  protected const DEFAULT_REBUILD_DELAY = 300;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly LockBackendInterface $lock,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly StreamWrapperManagerInterface $streamWrapperManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly StateInterface $state,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected readonly LoggerInterface $logger,
    protected readonly ScoltaContentGatherer $contentGatherer,
    protected readonly QueueFactory $queueFactory,
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
      $container->get('lock'),
      $container->get('config.factory'),
      $container->get('file_system'),
      $container->get('stream_wrapper_manager'),
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('cache_tags.invalidator'),
      $container->get('logger.channel.scolta'),
      $container->get('scolta.content_gatherer'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // Debounce: wait until the configured delay has elapsed since the LAST
    // content change so a burst of edits coalesces into one rebuild.
    $requestedAt = (int) $this->state->get('scolta.rebuild_requested_at', 0);
    if ($requestedAt > 0) {
      $delay = $this->autoRebuildDelay();
      $remaining = ($requestedAt + $delay) - time();
      if ($remaining > 0) {
        throw new SuspendQueueException(
          sprintf('Debouncing Scolta rebuild: %d seconds until the rebuild delay elapses.', $remaining),
          0,
          NULL,
          (float) $remaining
        );
      }
    }

    if (!$this->lock->acquire('scolta_build', 3600)) {
      throw new SuspendQueueException('Build lock held.');
    }

    try {
      $config = $this->configFactory->get('scolta.settings');

      $outputDir = $this->resolveDir($config->get('pagefind.output_dir') ?? 'public://scolta-pagefind');
      $stateDir = $this->resolveDir($config->get('pagefind.build_dir') ?? 'private://scolta-build');

      // Ensure directories exist.
      if (!is_dir($stateDir) && !$this->fileSystem->mkdir($stateDir, 0755, TRUE)) {
        $this->logger->error('Failed to create state directory: @dir', ['@dir' => $stateDir]);
        return;
      }
      if (!is_dir($outputDir) && !$this->fileSystem->mkdir($outputDir, 0755, TRUE)) {
        $this->logger->error('Failed to create output directory: @dir', ['@dir' => $outputDir]);
        return;
      }

      $siteName = $config->get('site_name') ?: ($this->configFactory->get('system.site')->get('name') ?? '');
      $language = $config->get('ai_languages')[0] ?? 'en';

      $totalCount = $this->contentGatherer->gatherCount('node', '');
      if ($totalCount === 0) {
        $this->logger->info('No content found to index.');
        $this->drainQueue();
        return;
      }

      $budget = MemoryBudgetConfig::load([
        'profile' => $config->get('memory_budget.profile') ?? 'conservative',
        'custom_bytes' => $config->get('memory_budget.custom_bytes'),
        'chunk_size' => $config->get('memory_budget.chunk_size'),
      ]);

      $orchestrator = new IndexBuildOrchestrator($stateDir, $outputDir, NULL, $language);
      $intent = BuildIntentFactory::fromFlags(FALSE, FALSE, $totalCount, $budget);

      // Stream content one entity at a time through the shared gatherer —
      // translations, text-format rendering, field mappings, and the alter
      // hook all apply, and the timestamp manifest lets unchanged entities
      // skip the full load. No eager loadMultiple() of the whole corpus.
      $exporter = new ContentExporter($outputDir);
      $items = $exporter->filterItems(
        $this->contentGatherer->gather('node', '', $siteName, 0, $orchestrator->getTimestampManifest(), FALSE)
      );

      $report = $orchestrator->build($intent, $items, $this->logger);

      if ($report->success) {
        // Increment generation counter so cached AI responses refresh.
        $generation = $this->state->get('scolta.generation', 0);
        $this->state->set('scolta.generation', $generation + 1);

        $this->cacheTagsInvalidator->invalidateTags(['scolta_search_index']);
        $this->drainQueue();
        $this->logger->info('Search index rebuilt via queue: @pages pages in @time s.', [
          '@pages' => $report->pagesProcessed,
          '@time' => $report->durationSeconds,
        ]);
      }
      else {
        $this->logger->error('Queue index rebuild failed: @error', [
          '@error' => $report->error ?? 'unknown',
        ]);
      }
    }
    finally {
      $this->lock->release('scolta_build');
    }
  }

  /**
   * Delete remaining queued rebuild requests after a successful build.
   *
   * Every entity save enqueues an item, so after one full rebuild the rest
   * of the queue is duplicate work for the same corpus state.
   */
  protected function drainQueue(): void {
    $queue = $this->queueFactory->get('scolta_rebuild');
    while ($item = $queue->claimItem()) {
      $queue->deleteItem($item);
    }
  }

  /**
   * The debounce delay: the Scolta backend's auto_rebuild_delay setting.
   *
   * Read from the first enabled search_api server using the scolta_pagefind
   * backend; falls back to 300 seconds when none exists (e.g. rebuilds
   * triggered purely by scolta.module's entity hooks).
   */
  protected function autoRebuildDelay(): int {
    try {
      $servers = $this->entityTypeManager->getStorage('search_api_server')->loadMultiple();
      foreach ($servers as $server) {
        // method_exists() rather than instanceof ServerInterface: search_api
        // classes are not autoloadable in every analysis environment.
        if (method_exists($server, 'getBackendId') && method_exists($server, 'getBackendConfig')
          && $server->getBackendId() === 'scolta_pagefind') {
          $backendConfig = $server->getBackendConfig();
          return max(60, min(3600, (int) ($backendConfig['auto_rebuild_delay'] ?? self::DEFAULT_REBUILD_DELAY)));
        }
      }
    }
    catch (\Throwable $e) {
      // search_api server storage unavailable — use the default.
    }
    return self::DEFAULT_REBUILD_DELAY;
  }

  /**
   * Resolve a stream-wrapper URI to a filesystem path.
   */
  protected function resolveDir(string $dir): string {
    if (str_contains($dir, '://')) {
      try {
        return $this->streamWrapperManager->getViaUri($dir)->realpath() ?: $dir;
      }
      catch (\Exception $e) {
        // Fall through with stream URI.
      }
    }
    return $dir;
  }

}
