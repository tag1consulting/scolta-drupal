<?php

declare(strict_types=1);

namespace Drupal\scolta\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;

/**
 * Drush commands for Scolta.
 *
 * scolta:export  — Export CMS content as HTML files for Pagefind indexing.
 * scolta:build   — Run export → pagefind CLI → deploy search page.
 * scolta:clear-cache — Clear Scolta's expansion/summary caches.
 */
class ScoltaCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Export content as minimal HTML files for Pagefind indexing.
   *
   * Queries Drupal entities and delegates content cleaning and HTML
   * generation to the shared Tag1\Scolta\Export\ContentExporter.
   */
  #[CLI\Command(name: 'scolta:export', aliases: ['se'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type to export (default: node)')]
  #[CLI\Option(name: 'bundle', description: 'Bundle/content type to export (default: all)')]
  #[CLI\Option(name: 'output-dir', description: 'Output directory for HTML files')]
  #[CLI\Usage(name: 'scolta:export node --bundle=article', description: 'Export all published articles')]
  #[CLI\Usage(name: 'scolta:export node --bundle=page --output-dir=/var/www/html/pagefind-site', description: 'Export pages to specific directory')]
  public function export(
    string $entity_type = 'node',
    array $options = ['bundle' => '', 'output-dir' => ''],
  ): void {
    $config = $this->configFactory->get('scolta.settings');
    $outputDir = $options['output-dir'] ?: '/var/www/html/pagefind-site';
    $bundle = $options['bundle'] ?: '';
    $siteName = $config->get('site_name') ?: 'Unknown';

    $exporter = new ContentExporter($outputDir);
    $exporter->prepareOutputDir();

    // Query published entities.
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1);

    if ($bundle) {
      $bundleKey = $this->entityTypeManager->getDefinition($entity_type)->getKey('bundle');
      if ($bundleKey) {
        $query->condition($bundleKey, $bundle);
      }
    }

    $ids = $query->execute();
    if (empty($ids)) {
      $this->logger()->warning('No published entities found.');
      return;
    }

    $entities = $storage->loadMultiple($ids);

    foreach ($entities as $entity) {
      // Extract body content — try common field names.
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

      $item = new ContentItem(
        id: (string) $entity->id(),
        title: $entity->label() ?: 'Untitled',
        bodyHtml: $body,
        url: $entity->toUrl()->setAbsolute(TRUE)->toString(),
        date: date('Y-m-d', $entity->getChangedTime()),
        siteName: $siteName,
      );

      $exporter->export($item);
    }

    $stats = $exporter->getStats();
    $this->logger()->success("Exported {$stats['exported']} entities to {$outputDir}/");
    if ($stats['skipped'] > 0) {
      $this->logger()->notice("Skipped {$stats['skipped']} entities with insufficient content.");
    }
  }

  /**
   * Build the Pagefind search index.
   *
   * Runs export → pagefind CLI → copies search page to docroot.
   */
  #[CLI\Command(name: 'scolta:build', aliases: ['sb'])]
  #[CLI\Option(name: 'entity-type', description: 'Entity type to export')]
  #[CLI\Option(name: 'bundle', description: 'Bundle to export')]
  #[CLI\Option(name: 'output-dir', description: 'Export directory')]
  #[CLI\Option(name: 'docroot', description: 'Docroot path')]
  public function build(
    array $options = [
      'entity-type' => 'node',
      'bundle' => '',
      'output-dir' => '/var/www/html/pagefind-site',
      'docroot' => 'docroot',
    ],
  ): void {
    $this->logger()->notice('Step 1: Exporting content...');
    $this->export($options['entity-type'], [
      'bundle' => $options['bundle'],
      'output-dir' => $options['output-dir'],
    ]);

    $this->logger()->notice('Step 2: Building Pagefind index...');
    $docroot = $options['docroot'];
    $outputDir = $options['output-dir'];
    $result = NULL;
    $output = [];
    exec("npx pagefind --site {$outputDir} --output-path {$docroot}/pagefind 2>&1", $output, $result);
    foreach ($output as $line) {
      $this->logger()->notice($line);
    }
    if ($result !== 0) {
      $this->logger()->error('Pagefind build failed.');
      return;
    }

    $this->logger()->success('Index built successfully.');
  }

  /**
   * Clear Scolta caches (expansion and summary).
   */
  #[CLI\Command(name: 'scolta:clear-cache', aliases: ['scc'])]
  public function clearCache(): void {
    $cache = \Drupal::cache('default');
    $cache->invalidateAll();
    $this->logger()->success('Scolta caches cleared.');
  }

}
