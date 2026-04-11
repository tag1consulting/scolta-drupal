<?php

declare(strict_types=1);

namespace Drupal\scolta\Commands;

use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Tag1\Scolta\SetupCheck;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\scolta\Service\ScoltaAiService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Prompt\DefaultPrompts;

/**
 * Drush commands for Scolta.
 *
 * Scolta:export  -- Export CMS content as HTML files.
 * scolta:build   -- Run export, pagefind CLI, deploy.
 * scolta:clear-cache -- Clear expansion/summary caches.
 * scolta:download-pagefind -- Download the Pagefind binary.
 */
class ScoltaCommands extends DrushCommands {

  /**
   * Constructs a ScoltaCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The default cache backend.
   * @param \Drupal\scolta\Service\ScoltaAiService $aiService
   *   The Scolta AI service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    private readonly CacheBackendInterface $cache,
    private readonly ScoltaAiService $aiService,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
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
      if (!$entity instanceof FieldableEntityInterface) {
        continue;
      }

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

      $changedTime = $entity instanceof EntityChangedInterface
        ? $entity->getChangedTime()
        : (int) ($entity->get('changed')->value ?? 0);

      $item = new ContentItem(
        id: (string) $entity->id(),
        title: $entity->label() ?: 'Untitled',
        bodyHtml: $body,
        url: $entity->toUrl()->setAbsolute(TRUE)->toString(),
        date: date('Y-m-d', $changedTime),
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
   * Runs export -> pagefind CLI -> copies search page to docroot.
   * When using the PHP indexer, content is processed in-memory without
   * exporting HTML files or invoking the Pagefind binary.
   */
  #[CLI\Command(name: 'scolta:build', aliases: ['sb'])]
  #[CLI\Option(name: 'entity-type', description: 'Entity type to export')]
  #[CLI\Option(name: 'bundle', description: 'Bundle to export')]
  #[CLI\Option(name: 'output-dir', description: 'Export directory')]
  #[CLI\Option(name: 'docroot', description: 'Docroot path')]
  #[CLI\Option(name: 'skip-pagefind', description: 'Export content only, skip Pagefind build')]
  #[CLI\Option(name: 'indexer', description: 'Indexer mode: php, binary, or auto (default: from config)')]
  #[CLI\Option(name: 'force', description: 'Force rebuild even if content has not changed')]
  public function build(
    array $options = [
      'entity-type' => 'node',
      'bundle' => '',
      'output-dir' => '/var/www/html/pagefind-site',
      'docroot' => 'docroot',
      'skip-pagefind' => FALSE,
      'indexer' => '',
      'force' => FALSE,
    ],
  ): void {
    $config = $this->configFactory->get('scolta.settings');

    // Resolve indexer mode: CLI option overrides config.
    $indexerMode = $options['indexer'] ?: ($config->get('indexer') ?: 'auto');

    if ($indexerMode === 'auto') {
      $indexerMode = $this->resolveAutoIndexer($config);
    }

    if ($indexerMode === 'php') {
      $this->buildWithPhpIndexer($options, $config, (bool) $options['force']);
    }
    else {
      $this->buildWithBinary($options);
    }

    // Cache resolved prompts regardless of indexer mode.
    $this->logger()->notice('Caching resolved prompts...');
    $this->cacheResolvedPrompts();
  }

  /**
   * Resolve 'auto' indexer mode based on binary availability.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   *
   * @return string
   *   'binary' if the Pagefind binary is available, 'php' otherwise.
   */
  private function resolveAutoIndexer($config): string {
    $resolver = new PagefindBinary(
      configuredPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );

    $binary = $resolver->resolve();
    if ($binary !== NULL) {
      $this->logger()->notice('Auto-detected indexer: binary (Pagefind available).');
      return 'binary';
    }

    $this->logger()->notice('Auto-detected indexer: php (Pagefind binary not available).');
    return 'php';
  }

  /**
   * Build using the existing binary pipeline (export HTML + run Pagefind).
   */
  private function buildWithBinary(array $options): void {
    $this->logger()->notice('Step 1: Exporting content...');
    $this->export($options['entity-type'], [
      'bundle' => $options['bundle'],
      'output-dir' => $options['output-dir'],
    ]);

    if ($options['skip-pagefind']) {
      $this->logger()->success('Export complete. Skipped Pagefind build (--skip-pagefind).');
      return;
    }

    $this->logger()->notice('Step 2: Building Pagefind index (binary)...');
    $docroot = $options['docroot'];
    $this->runPagefind($options['output-dir'], $docroot . '/pagefind');
  }

  /**
   * Build using the PHP indexer (in-memory, no Pagefind binary needed).
   *
   * @param array $options
   *   The command options.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The Scolta settings config.
   * @param bool $force
   *   Whether to skip the fingerprint check and force a rebuild.
   */
  private function buildWithPhpIndexer(array $options, $config, bool $force): void {
    $entityType = $options['entity-type'] ?: 'node';
    $bundle = $options['bundle'] ?: '';
    $siteName = $config->get('site_name') ?: 'Unknown';

    // Resolve output directory for the PHP indexer.
    $outputDir = $config->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    if (str_contains($outputDir, '://')) {
      try {
        $resolvedOutputDir = $this->streamWrapperManager
          ->getViaUri($outputDir)->realpath() ?: $outputDir;
      }
      catch (\Exception $e) {
        $resolvedOutputDir = $outputDir;
      }
    }
    else {
      $resolvedOutputDir = $outputDir;
    }

    $stateDir = $config->get('pagefind.build_dir') ?? 'private://scolta-build';
    if (str_contains($stateDir, '://')) {
      try {
        $resolvedStateDir = $this->streamWrapperManager
          ->getViaUri($stateDir)->realpath() ?: $stateDir;
      }
      catch (\Exception $e) {
        $resolvedStateDir = $stateDir;
      }
    }
    else {
      $resolvedStateDir = $stateDir;
    }

    // Ensure directories exist.
    if (!is_dir($resolvedStateDir) && !mkdir($resolvedStateDir, 0755, TRUE)) {
      $this->logger()->error('Failed to create state directory: {dir}', ['dir' => $resolvedStateDir]);
      return;
    }
    if (!is_dir($resolvedOutputDir) && !mkdir($resolvedOutputDir, 0755, TRUE)) {
      $this->logger()->error('Failed to create output directory: {dir}', ['dir' => $resolvedOutputDir]);
      return;
    }

    // Step 1: Gather content from Drupal.
    $this->logger()->notice('Step 1: Gathering content (PHP indexer)...');
    $items = $this->gatherContentItems($entityType, $bundle, $siteName);

    if (empty($items)) {
      $this->logger()->warning('No content found to index.');
      return;
    }

    $this->logger()->notice('Found {count} content items.', ['count' => count($items)]);

    // Step 2: Filter through ContentExporter.
    $exporter = new ContentExporter($resolvedOutputDir);
    $filteredItems = $exporter->exportToItems($items);
    $skipped = count($items) - count($filteredItems);

    if ($skipped > 0) {
      $this->logger()->notice('Filtered out {count} items with insufficient content.', ['count' => $skipped]);
    }

    if (empty($filteredItems)) {
      $this->logger()->warning('No items passed content filter.');
      return;
    }

    $this->logger()->notice('{count} items ready for indexing.', ['count' => count($filteredItems)]);

    // Step 3: Fingerprint check (skip if --force).
    $language = $config->get('ai_languages')[0] ?? 'en';
    $indexer = new PhpIndexer(
      stateDir: $resolvedStateDir,
      outputDir: $resolvedOutputDir,
      hmacSecret: NULL,
      language: $language,
    );

    if (!$force) {
      $fingerprint = $indexer->shouldBuild($filteredItems);
      if ($fingerprint === NULL) {
        $this->logger()->success('Content unchanged — skipping rebuild. Use --force to override.');
        return;
      }
      $this->logger()->notice('Content fingerprint changed, rebuilding index.');
    }
    else {
      $this->logger()->notice('Forced rebuild (--force).');
    }

    // Step 4: Chunk and process.
    $chunkSize = 100;
    $chunks = array_chunk($filteredItems, $chunkSize);
    $totalPages = count($filteredItems);
    $totalProcessed = 0;

    $this->logger()->notice('Step 2: Processing {chunks} chunk(s)...', ['chunks' => count($chunks)]);

    foreach ($chunks as $chunkNumber => $chunk) {
      $processed = $indexer->processChunk($chunk, $chunkNumber, $totalPages);
      $totalProcessed += $processed;
      $this->logger()->notice('  Chunk {n}/{total}: processed {count} pages.', [
        'n' => $chunkNumber + 1,
        'total' => count($chunks),
        'count' => $processed,
      ]);
    }

    // Step 5: Finalize.
    $this->logger()->notice('Step 3: Finalizing index...');
    $result = $indexer->finalize();

    if ($result->success) {
      // Write fingerprint for future change detection.
      $fp = PhpIndexer::computeFingerprint($filteredItems);
      file_put_contents($resolvedOutputDir . '/.scolta-state', $fp);

      // Increment generation counter.
      $generation = $this->state->get('scolta.generation', 0);
      $this->state->set('scolta.generation', $generation + 1);

      $this->logger()->success('PHP indexer: {message} in {time}s.', [
        'message' => $result->message,
        'time' => $result->elapsedSeconds,
      ]);
    }
    else {
      $this->logger()->error('PHP indexer failed: {error}', [
        'error' => $result->error ?? $result->message,
      ]);
    }
  }

  /**
   * Gather content items from Drupal entities.
   *
   * Queries published entities and converts them to ContentItem objects.
   *
   * @param string $entityType
   *   The entity type to query.
   * @param string $bundle
   *   The bundle to filter by (empty for all).
   * @param string $siteName
   *   The site name for metadata.
   *
   * @return \Tag1\Scolta\Export\ContentItem[]
   *   Array of content items.
   */
  private function gatherContentItems(string $entityType, string $bundle, string $siteName): array {
    $storage = $this->entityTypeManager->getStorage($entityType);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1);

    if ($bundle) {
      $bundleKey = $this->entityTypeManager->getDefinition($entityType)->getKey('bundle');
      if ($bundleKey) {
        $query->condition($bundleKey, $bundle);
      }
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $entities = $storage->loadMultiple($ids);
    $items = [];

    foreach ($entities as $entity) {
      if (!$entity instanceof FieldableEntityInterface) {
        continue;
      }

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

      $changedTime = $entity instanceof EntityChangedInterface
        ? $entity->getChangedTime()
        : (int) ($entity->get('changed')->value ?? 0);

      $items[] = new ContentItem(
        id: (string) $entity->id(),
        title: $entity->label() ?: 'Untitled',
        bodyHtml: $body,
        url: $entity->toUrl()->setAbsolute(TRUE)->toString(),
        date: date('Y-m-d', $changedTime),
        siteName: $siteName,
      );
    }

    return $items;
  }

  /**
   * Rebuild the Pagefind index from existing exported HTML files.
   *
   * Skips the content export step — runs only the Pagefind CLI.
   * Useful after config changes or Pagefind upgrades.
   */
  #[CLI\Command(name: 'scolta:rebuild-index', aliases: ['sri'])]
  #[CLI\Option(name: 'source-dir', description: 'Source directory with exported HTML files')]
  #[CLI\Option(name: 'output-dir', description: 'Pagefind output directory')]
  public function rebuildIndex(
    array $options = [
      'source-dir' => '/var/www/html/pagefind-site',
      'output-dir' => '',
    ],
  ): void {
    $sourceDir = $options['source-dir'];
    $outputDir = $options['output-dir'] ?: dirname($sourceDir) . '/pagefind';
    $this->logger()->notice('Rebuilding Pagefind index from existing HTML files...');
    $this->runPagefind($sourceDir, $outputDir);
  }

  /**
   * Run the Pagefind CLI to build a search index.
   */
  private function runPagefind(string $sourceDir, string $outputDir): void {
    $config = $this->configFactory->get('scolta.settings');
    $resolver = new PagefindBinary(
      configuredPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );

    $binary = $resolver->resolve();
    if ($binary === NULL) {
      $status = $resolver->status();
      $this->logger()->error($status['message']);
      return;
    }

    $this->logger()->notice('Using Pagefind: {binary} (resolved via {via})', [
      'binary' => $binary,
      'via' => $resolver->resolvedVia(),
    ]);

    $cmd = $binary
      . ' --site ' . escapeshellarg($sourceDir)
      . ' --output-path ' . escapeshellarg($outputDir)
      . ' 2>&1';
    $result = NULL;
    $output = [];
    exec($cmd, $output, $result);
    foreach ($output as $line) {
      $this->logger()->notice($line);
    }
    if ($result !== 0) {
      $this->logger()->error('Pagefind build failed.');
      return;
    }

    // Increment generation counter to invalidate caches.
    $generation = $this->state->get('scolta.generation', 0);
    $this->state->set('scolta.generation', $generation + 1);

    $this->logger()->success('Index built successfully.');
  }

  /**
   * Pre-resolve and cache all prompt templates.
   *
   * Stores resolved prompts in Drupal's cache so API endpoints can
   * read them without resolving on every request.
   */
  private function cacheResolvedPrompts(): void {
    $config = $this->aiService->getConfig();
    $siteName = $config->siteName;
    $siteDescription = $config->siteDescription;

    $prompts = [
      'expand_query' => DefaultPrompts::resolve(DefaultPrompts::EXPAND_QUERY, $siteName, $siteDescription),
      'summarize' => DefaultPrompts::resolve(DefaultPrompts::SUMMARIZE, $siteName, $siteDescription),
      'follow_up' => DefaultPrompts::resolve(DefaultPrompts::FOLLOW_UP, $siteName, $siteDescription),
    ];

    $cacheTtl = $config->cacheTtl > 0 ? $config->cacheTtl : 2592000;
    foreach ($prompts as $name => $resolved) {
      $this->cache->set("scolta.prompt.{$name}", $resolved, time() + $cacheTtl);
    }

    $this->logger()->success('Cached resolved prompts for: ' . implode(', ', array_keys($prompts)));
  }

  /**
   * Clear Scolta caches (expansion and summary).
   */
  #[CLI\Command(name: 'scolta:clear-cache', aliases: ['scc'])]
  public function clearCache(): void {
    $this->cache->deleteAll();
    $this->logger()->success('Scolta caches cleared.');
  }

  /**
   * Verify Scolta dependencies and configuration.
   *
   * Checks PHP version, Pagefind binary, and AI key.
   */
  #[CLI\Command(name: 'scolta:check-setup', aliases: ['scs'])]
  public function checkSetup(): void {
    $config = $this->configFactory->get('scolta.settings');

    $results = SetupCheck::run(
      configuredBinaryPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT')
        ? DRUPAL_ROOT : getcwd(),
      aiApiKey: $this->aiService->getApiKey(),
    );

    foreach ($results as $r) {
      $icon = match ($r['status']) {
        'pass' => '[OK]',
        'warn' => '[!!]',
        'fail' => '[FAIL]',
        default => '[??]',
      };
      $method = match ($r['status']) {
        'fail' => 'error',
        'warn' => 'warning',
        default => 'notice',
      };
      $this->logger()->$method("{$icon} {$r['name']}: {$r['message']}");
    }

    $exit = SetupCheck::exitCode($results);
    if ($exit === 0) {
      $this->logger()->success('All critical checks passed.');
    }
    else {
      $this->logger()->error('One or more critical checks failed.');
    }
  }

  /**
   * Show Scolta status: tracker, index, binary, AI provider.
   */
  #[CLI\Command(name: 'scolta:status', aliases: ['sst'])]
  public function status(): void {
    $config = $this->configFactory->get('scolta.settings');

    // Search API index status.
    $this->logger()->notice('--- Search API ---');
    try {
      $indexes = $this->entityTypeManager
        ->getStorage('search_api_index')
        ->loadMultiple();
      $found = FALSE;
      foreach ($indexes as $index) {
        if ($index->getServerId() && str_contains($index->getServerId(), 'scolta')) {
          $tracker = $index->getTrackerInstance();
          $indexed = $tracker->getIndexedItemsCount();
          $total = $tracker->getTotalItemsCount();
          $statusLabel = $index->status() ? 'enabled' : 'disabled';
          $this->logger()->notice("  Index: {$index->label()} ({$statusLabel})");
          $this->logger()->notice("  Indexed: {$indexed}/{$total}");
          $found = TRUE;
        }
      }
      if (!$found) {
        $this->logger()->warning('  No Scolta index configured.');
      }
    }
    catch (\Exception $e) {
      $this->logger()->warning('  Could not query Search API: ' . $e->getMessage());
    }

    // Pagefind binary.
    $this->logger()->notice('--- Pagefind Binary ---');
    $resolver = new PagefindBinary(
      configuredPath: $config->get('pagefind.binary'),
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );
    $binaryStatus = $resolver->status();
    if ($binaryStatus['available']) {
      $this->logger()->notice("  {$binaryStatus['message']}");
    }
    else {
      $this->logger()->warning($binaryStatus['message']);
    }

    // Pagefind index.
    $this->logger()->notice('--- Pagefind Index ---');
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
    if (file_exists($resolvedDir . '/pagefind.js')) {
      $fragmentCount = count(glob($resolvedDir . '/fragment/*') ?: []);
      $mtime = filemtime($resolvedDir . '/pagefind.js');
      $this->logger()->notice("  Path:       {$outputDir}");
      $this->logger()->notice("  Fragments:  {$fragmentCount}");
      $this->logger()->notice("  Last built: " . ($mtime ? date('Y-m-d H:i:s', $mtime) : 'unknown'));
    }
    else {
      $this->logger()->notice("  Path: {$outputDir} (no index built yet)");
    }

    // AI provider.
    $this->logger()->notice('--- AI Provider ---');
    if ($this->aiService->hasDrupalAiModule()) {
      $this->logger()->notice('  Provider: Drupal AI module');
    }
    else {
      $provider = $config->get('ai_provider') ?? 'anthropic';
      $this->logger()->notice("  Provider: {$provider} (built-in)");
    }
    $keySource = $this->aiService->getApiKeySource();
    $this->logger()->notice("  API key:  {$keySource}");

    // Generation counter.
    $generation = $this->state->get('scolta.generation', 0);
    $this->logger()->notice("  Cache generation: {$generation}");
  }

  /**
   * Download the Pagefind binary for the current platform.
   *
   * Detects OS and architecture, fetches the latest release from GitHub,
   * and extracts the binary to the specified location.
   */
  #[CLI\Command(name: 'scolta:download-pagefind', aliases: ['sdp'])]
  #[CLI\Option(name: 'version', description: 'Pagefind version to download (default: latest)')]
  #[CLI\Option(name: 'dest', description: 'Destination directory for the binary')]
  #[CLI\Usage(name: 'scolta:download-pagefind', description: 'Download latest Pagefind binary')]
  #[CLI\Usage(name: 'scolta:download-pagefind --version=1.1.0 --dest=/usr/local/bin', description: 'Download specific version to specific directory')]
  public function downloadPagefind(
    array $options = ['version' => 'latest', 'dest' => ''],
  ): void {
    // Detect platform.
    $os = PHP_OS_FAMILY;
    $arch = php_uname('m');

    $platformMap = [
      'Darwin' => [
        'x86_64' => 'x86_64-apple-darwin',
        'arm64' => 'aarch64-apple-darwin',
      ],
      'Linux' => [
        'x86_64' => 'x86_64-unknown-linux-musl',
        'aarch64' => 'aarch64-unknown-linux-musl',
        'arm64' => 'aarch64-unknown-linux-musl',
      ],
      'Windows' => [
        'x86_64' => 'x86_64-pc-windows-msvc',
        'AMD64' => 'x86_64-pc-windows-msvc',
      ],
    ];

    if (!isset($platformMap[$os][$arch])) {
      $this->logger()->error("Unsupported platform: {$os} {$arch}");
      return;
    }

    $platform = $platformMap[$os][$arch];
    $version = $options['version'];
    $resolver = new PagefindBinary(
      projectDir: defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd(),
    );
    $dest = $options['dest'] ?: $resolver->downloadTargetDir();

    // Resolve latest version from GitHub API.
    if ($version === 'latest') {
      $this->logger()->notice('Fetching latest Pagefind release info from GitHub...');
      try {
        $response = $this->httpClient->request('GET', 'https://api.github.com/repos/CloudCannon/pagefind/releases/latest', [
          'headers' => [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'Scolta-Drupal',
          ],
          'timeout' => 15,
        ]);
        $releaseData = json_decode((string) $response->getBody(), TRUE);
        $version = ltrim($releaseData['tag_name'] ?? '', 'v');
        if (empty($version)) {
          $this->logger()->error('Could not determine latest Pagefind version from GitHub.');
          return;
        }
      }
      catch (\Exception $e) {
        $this->logger()->error('Failed to fetch release info from GitHub: ' . $e->getMessage());
        return;
      }
    }

    $this->logger()->notice("Downloading Pagefind v{$version} for {$platform}...");

    $ext = ($os === 'Windows') ? 'zip' : 'tar.gz';
    $filename = "pagefind-v{$version}-{$platform}.{$ext}";
    $url = "https://github.com/CloudCannon/pagefind/releases/download/v{$version}/{$filename}";

    // Download the archive.
    $tempFile = sys_get_temp_dir() . '/' . $filename;
    try {
      $response = $this->httpClient->request('GET', $url, [
        'sink' => $tempFile,
        'timeout' => 120,
        'headers' => [
          'User-Agent' => 'Scolta-Drupal',
        ],
      ]);

      if ($response->getStatusCode() !== 200) {
        $this->logger()->error("Download failed with HTTP {$response->getStatusCode()}");
        return;
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Download failed: ' . $e->getMessage());
      return;
    }

    // Extract the binary.
    if (!is_dir($dest)) {
      mkdir($dest, 0755, TRUE);
    }

    try {
      if ($ext === 'tar.gz') {
        $phar = new \PharData($tempFile);
        $phar->extractTo($dest, NULL, TRUE);
      }
      else {
        $zip = new \ZipArchive();
        if ($zip->open($tempFile) === TRUE) {
          $zip->extractTo($dest);
          $zip->close();
        }
        else {
          $this->logger()->error('Failed to open zip archive.');
          return;
        }
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Extraction failed: ' . $e->getMessage());
      return;
    }

    // Make binary executable on Unix.
    $binaryPath = rtrim($dest, '/') . '/pagefind';
    if ($os !== 'Windows' && file_exists($binaryPath)) {
      chmod($binaryPath, 0755);
    }

    // Clean up temp file.
    if (file_exists($tempFile)) {
      unlink($tempFile);
    }

    $this->logger()->success("Pagefind v{$version} installed to {$dest}/");

    // Auto-update Drupal config to point to the downloaded binary.
    $editableConfig = $this->configFactory->getEditable('scolta.settings');
    $editableConfig->set('pagefind.binary', $binaryPath);
    $editableConfig->save();
    $this->logger()->notice('Drupal config updated: pagefind.binary = {path}', [
      'path' => $binaryPath,
    ]);

    // Verify the binary works.
    $output = [];
    $exitCode = NULL;
    exec("{$binaryPath} --version 2>&1", $output, $exitCode);
    if ($exitCode === 0) {
      $this->logger()->notice('Verified: ' . implode(' ', $output));
    }
    else {
      $this->logger()->warning('Binary was extracted but --version check failed. You may need to adjust your PATH or permissions.');
    }
  }

}
