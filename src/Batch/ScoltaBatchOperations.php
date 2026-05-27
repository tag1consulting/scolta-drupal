<?php

declare(strict_types=1);

namespace Drupal\scolta\Batch;

use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Batch operations for Scolta index rebuilding.
 *
 * Provides static callback methods for Drupal's Batch API to process
 * content items in chunks and finalize the search index.
 *
 * @since 1.0.0-rc1
 * @stability experimental
 */
class ScoltaBatchOperations {

  /**
   * Load entity IDs, convert to ContentItems, filter, and index one chunk.
   *
   * This is the batch callback used by "Index Now". Each batch step receives
   * a small slice of entity IDs, loads only those entities, and processes them
   * so that no single web request has to load the full corpus into memory.
   *
   * @param int $chunkIdx
   *   The zero-based chunk index (used as the PhpIndexer chunk position).
   * @param array $entityIds
   *   Entity IDs to load and process in this step.
   * @param int $totalCount
   *   Total entity ID count across all chunks (used as totalPages estimate).
   * @param string $siteName
   *   Site name for ContentItem metadata.
   * @param array $config
   *   Configuration array with state_dir, output_dir, hmac_secret, language.
   * @param array $context
   *   The batch context array.
   */
  public static function loadAndProcessChunk(int $chunkIdx, array $entityIds, int $totalCount, string $siteName, array $config, array &$context): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $entities = $storage->loadMultiple($entityIds);

    $items = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof FieldableEntityInterface) {
        continue;
      }

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
        url: $entity->toUrl()->toString(),
        date: date('Y-m-d', $changedTime),
        siteName: $siteName,
      );
    }

    if (!empty($items)) {
      $exporter = new ContentExporter($config['output_dir']);
      $filtered = iterator_to_array($exporter->filterItems($items));

      if (!empty($filtered)) {
        $indexer = new PhpIndexer(
          $config['state_dir'],
          $config['output_dir'],
          $config['hmac_secret'] ?? NULL,
          $config['language'] ?? 'en'
        );
        $indexer->processChunk($filtered, $chunkIdx, $totalCount);
      }
    }

    $context['results']['completed_chunks'] = ($context['results']['completed_chunks'] ?? 0) + 1;
    $context['message'] = t('Indexed chunk @num', ['@num' => $chunkIdx + 1]);
  }

  /**
   * Process a chunk of content items.
   *
   * @param int $chunkIdx
   *   The zero-based chunk index.
   * @param array $chunk
   *   Array of ContentItem objects to process.
   * @param int $totalPages
   *   Total number of pages across all chunks.
   * @param array $config
   *   Configuration array with state_dir, output_dir, hmac_secret, language.
   * @param array $context
   *   The batch context array.
   */
  public static function processChunk(int $chunkIdx, array $chunk, int $totalPages, array $config, array &$context): void {
    $indexer = new PhpIndexer(
      $config['state_dir'],
      $config['output_dir'],
      $config['hmac_secret'] ?? NULL,
      $config['language'] ?? 'en'
    );
    $indexer->processChunk($chunk, $chunkIdx, $totalPages);

    $context['results']['completed_chunks'] = ($context['results']['completed_chunks'] ?? 0) + 1;
    $context['message'] = t('Processed chunk @num', ['@num' => $chunkIdx + 1]);
  }

  /**
   * Finalize the search index after all chunks are processed.
   *
   * @param array $config
   *   Configuration array with state_dir, output_dir, hmac_secret, language.
   * @param array $context
   *   The batch context array.
   */
  public static function finalize(array $config, array &$context): void {
    $indexer = new PhpIndexer(
      $config['state_dir'],
      $config['output_dir'],
      $config['hmac_secret'] ?? NULL,
      $config['language'] ?? 'en'
    );
    $result = $indexer->finalize();
    $context['results']['success'] = $result->success;
    $context['results']['message'] = $result->message;
    $context['message'] = t('Finalizing index...');
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed without errors.
   * @param array $results
   *   The results array from batch operations.
   * @param array $operations
   *   Any remaining operations (if batch was interrupted).
   */
  public static function finished(bool $success, array $results, array $operations): void {
    if ($success && ($results['success'] ?? FALSE)) {
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['scolta_search_index']);
      // Store in State instead of Messenger so the notice persists across page
      // loads until the admin explicitly dismisses it or a new rebuild starts.
      \Drupal::state()->set('scolta.rebuild_notice', self::buildNoticeData(
        'ok',
        (string) ($results['message'] ?? '')
      ));
    }
    else {
      \Drupal::state()->set('scolta.rebuild_notice', self::buildNoticeData('error', ''));
    }
  }

  /**
   * Build the notice data array stored in State after a rebuild.
   *
   * Extracted as a public static method so unit tests can verify the
   * data structure without invoking \Drupal::state() directly.
   *
   * @param string $result
   *   Result status: 'ok' or 'error'.
   * @param string $message
   *   Human-readable detail string from the indexer result.
   *
   * @return array
   *   Notice data with notice_id, result, message, timestamp keys.
   */
  public static function buildNoticeData(string $result, string $message): array {
    return [
      'notice_id' => 'scolta_notice_' . bin2hex(random_bytes(8)),
      'result'    => $result,
      'message'   => $message,
      'timestamp' => time(),
    ];
  }

}
