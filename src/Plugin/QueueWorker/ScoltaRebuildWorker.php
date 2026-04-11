<?php

declare(strict_types=1);

namespace Drupal\scolta\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
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
 * @since 0.2.0
 * @stability experimental
 */
class ScoltaRebuildWorker extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $lock = \Drupal::lock();
    if (!$lock->acquire('scolta_build', 3600)) {
      throw new SuspendQueueException('Build lock held.');
    }

    try {
      $config = \Drupal::config('scolta.settings');
      $fileSystem = \Drupal::service('file_system');
      $streamWrapperManager = \Drupal::service('stream_wrapper_manager');

      // Resolve output directory.
      $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
      if (str_contains($outputDir, '://')) {
        try {
          $outputDir = $streamWrapperManager
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
          $stateDir = $streamWrapperManager
            ->getViaUri($stateDir)->realpath() ?: $stateDir;
        }
        catch (\Exception $e) {
          // Fall through with stream URI.
        }
      }

      // Ensure directories exist.
      if (!is_dir($stateDir) && !mkdir($stateDir, 0755, TRUE)) {
        \Drupal::logger('scolta')->error('Failed to create state directory: @dir', ['@dir' => $stateDir]);
        return;
      }
      if (!is_dir($outputDir) && !mkdir($outputDir, 0755, TRUE)) {
        \Drupal::logger('scolta')->error('Failed to create output directory: @dir', ['@dir' => $outputDir]);
        return;
      }

      // Gather content from published nodes.
      $siteName = $config->get('site_name') ?? '';
      $entityStorage = \Drupal::entityTypeManager()->getStorage('node');
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
        \Drupal::logger('scolta')->info('No content found to index.');
        return;
      }

      // Filter through ContentExporter.
      $exporter = new ContentExporter($outputDir);
      $filteredItems = $exporter->exportToItems($items);

      if (empty($filteredItems)) {
        \Drupal::logger('scolta')->info('No items passed content filter.');
        return;
      }

      // Create indexer and check for changes.
      $language = $config->get('ai_languages')[0] ?? 'en';
      $indexer = new PhpIndexer($stateDir, $outputDir, NULL, $language);

      if ($indexer->shouldBuild($filteredItems) === NULL) {
        \Drupal::logger('scolta')->info('No changes detected, skipping rebuild.');
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
        $fp = PhpIndexer::computeFingerprint($filteredItems);
        file_put_contents($outputDir . '/.scolta-state', $fp);

        // Increment generation counter.
        $state = \Drupal::state();
        $generation = $state->get('scolta.generation', 0);
        $state->set('scolta.generation', $generation + 1);

        \Drupal::logger('scolta')->info('Search index rebuilt via queue: @msg', [
          '@msg' => $result->message,
        ]);
      }
      else {
        \Drupal::logger('scolta')->error('Queue index rebuild failed: @error', [
          '@error' => $result->error ?? $result->message,
        ]);
      }
    }
    finally {
      $lock->release('scolta_build');
    }
  }

}
