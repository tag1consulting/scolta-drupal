<?php

declare(strict_types=1);

namespace Drupal\scolta\Batch;

use Tag1\Scolta\Index\PhpIndexer;

/**
 * Batch operations for Scolta index rebuilding.
 *
 * Provides static callback methods for Drupal's Batch API to process
 * content items in chunks and finalize the search index.
 *
 * @since 0.2.0
 * @stability experimental
 */
class ScoltaBatchOperations {

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
      \Drupal::messenger()->addMessage(t('Search index rebuilt: @msg', [
        '@msg' => $results['message'] ?? '',
      ]));
    }
    else {
      \Drupal::messenger()->addError(t('Index rebuild failed.'));
    }
  }

}
