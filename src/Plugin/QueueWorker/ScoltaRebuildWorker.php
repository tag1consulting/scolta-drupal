<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\QueueWorker;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Queue worker for rebuilding the Scolta search index.
 *
 * Processes queued rebuild requests triggered by entity changes
 * when auto-rebuild is enabled in the Scolta configuration.
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!$this->lock->acquire('scolta_build', 3600)) {
      throw new SuspendQueueException('Build lock held.');
    }

    try {
      $config = $this->configFactory->get('scolta.settings');

      // Resolve output directory.
      $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
      if (str_contains($outputDir, '://')) {
        try {
          $outputDir = $this->streamWrapperManager
            ->getViaUri($outputDir)->realpath() ?: $outputDir;
        }
        catch (\Exception $e) {
          // Fall through with stream URI.
        }
      }

      // Resolve state directory.
      $stateDir = $config->get('pagefind.build_dir') ?? 'private://scolta-build';
      if (str_contains($stateDir, '://')) {
        try {
          $stateDir = $this->streamWrapperManager
            ->getViaUri($stateDir)->realpath() ?: $stateDir;
        }
        catch (\Exception $e) {
          // Fall through with stream URI.
        }
      }

      // Ensure directories exist.
      if (!is_dir($stateDir) && !$this->fileSystem->mkdir($stateDir, 0755, TRUE)) {
        $this->logger->error('Failed to create state directory: @dir', ['@dir' => $stateDir]);
        return;
      }
      if (!is_dir($outputDir) && !$this->fileSystem->mkdir($outputDir, 0755, TRUE)) {
        $this->logger->error('Failed to create output directory: @dir', ['@dir' => $outputDir]);
        return;
      }

      // Gather content from published nodes.
      $siteName = $config->get('site_name') ?: ($this->configFactory->get('system.site')->get('name') ?? '');
      $entityStorage = $this->entityTypeManager->getStorage('node');
      $ids = $entityStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->execute();
      $entities = $entityStorage->loadMultiple($ids);

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
          title: $entity->label() ?? '',
          bodyHtml: $body,
          url: $entity->toUrl()->toString(),
          date: date('Y-m-d', $changedTime),
          siteName: $siteName,
        );
      }

      if (empty($items)) {
        $this->logger->info('No content found to index.');
        return;
      }

      // Filter through ContentExporter.
      $exporter = new ContentExporter($outputDir);
      $filteredItems = $exporter->exportToItems($items);

      if (empty($filteredItems)) {
        $this->logger->info('No items passed content filter.');
        return;
      }

      // Create indexer and check for changes.
      $language = $config->get('ai_languages')[0] ?? 'en';
      $indexer = new PhpIndexer($stateDir, $outputDir, NULL, $language);

      if ($indexer->shouldBuild($filteredItems) === NULL) {
        $this->logger->info('No changes detected, skipping rebuild.');
        return;
      }

      // Process chunks.
      $totalPages = count($filteredItems);
      foreach (array_chunk($filteredItems, 100) as $idx => $chunk) {
        $indexer->processChunk($chunk, $idx, $totalPages);
      }

      // Finalize the index.
      $result = $indexer->finalize();

      if ($result->success) {
        // Write fingerprint for future change detection.
        $this->writeFingerprint(
          $outputDir . '/.scolta-state',
          PhpIndexer::computeFingerprint($filteredItems)
        );

        // Increment generation counter.
        $generation = $this->state->get('scolta.generation', 0);
        $this->state->set('scolta.generation', $generation + 1);

        $this->cacheTagsInvalidator->invalidateTags(['scolta_search_index']);
        $this->logger->info('Search index rebuilt via queue: @msg', [
          '@msg' => $result->message,
        ]);
      }
      else {
        $this->logger->error('Queue index rebuild failed: @error', [
          '@error' => $result->error ?? $result->message,
        ]);
      }
    }
    finally {
      $this->lock->release('scolta_build');
    }
  }

  /**
   * Write the index fingerprint used for change detection on the next run.
   *
   * A failed write is logged but never fatal: the next queue run simply
   * rebuilds unconditionally because the fingerprint is missing.
   *
   * @param string $statePath
   *   Absolute path of the fingerprint file.
   * @param string $fingerprint
   *   The computed corpus fingerprint.
   */
  protected function writeFingerprint(string $statePath, string $fingerprint): void {
    // phpcs:ignore Drupal.Functions.DiscouragedFunctions -- absolute path outside Drupal stream wrappers; saveData() requires a URI scheme.
    if (@file_put_contents($statePath, $fingerprint) === FALSE) {
      $this->logger->error('Failed to write index fingerprint to @path', ['@path' => $statePath]);
    }
  }

}
