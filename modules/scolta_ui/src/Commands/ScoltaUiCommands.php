<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Commands;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\scolta_ui\Service\ScoltaAiService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Tag1\Scolta\Prompt\DefaultPrompts;
use Tag1\Scolta\SetupCheck;

/**
 * Drush commands for the query-time half of Scolta.
 *
 * The AI tier and the prompt cache. Everything about building an index lives
 * in scolta's own command class, so a site that installs only one of the two
 * modules gets exactly the commands it can run.
 */
class ScoltaUiCommands extends DrushCommands {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly CacheBackendInterface $cache,
    private readonly ScoltaAiService $aiService,
  ) {
    parent::__construct();
  }

  /**
   * Clear Scolta caches (expansion and summary).
   *
   * Scolta shares the cache.default bin with every other module, so wiping
   * the bin is off limits. AI expansion/summary entries embed the
   * scolta.generation counter in their cache key, so bumping the generation
   * orphans all existing entries; the resolved-prompt entries use known
   * fixed keys and are deleted directly.
   */
  #[CLI\Command(name: 'scolta:clear-cache', aliases: ['scc'])]
  public function clearCache(): void {
    $generation = $this->state->get('scolta.generation', 0);
    $this->state->set('scolta.generation', $generation + 1);

    $this->cache->deleteMultiple([
      'scolta.prompt.expand_query',
      'scolta.prompt.summarize',
      'scolta.prompt.follow_up',
    ]);

    $this->logger()->success('Scolta caches cleared (generation bumped, resolved prompts deleted).');
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
      // The AI-key row names the source and reports an overridden Amazee.ai
      // credential, from the same resolution the settings form and /health
      // read (scolta-php#252).
      resolvedKey: $this->aiService->resolveApiKey(),
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
   * Pre-resolve and cache all prompt templates.
   *
   * The endpoints resolve and cache prompts on first use, so this is only a
   * warm-up. It used to run at the end of every index build, which coupled
   * query-time state to a build that has nothing to do with it — and on a
   * site that only renders search there is no build to hang it off.
   */
  #[CLI\Command(name: 'scolta:cache-prompts', aliases: ['scp'])]
  #[CLI\Usage(name: 'scolta:cache-prompts', description: 'Resolve and cache the AI prompt templates')]
  public function cachePrompts(): void {
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
   * Report the AI tier's configuration and effective key.
   *
   * The index half of what `scolta:status` used to print stays with the
   * backend; this is the half that describes what answers an AI request.
   */
  #[CLI\Command(name: 'scolta:ai-status', aliases: ['sais'])]
  #[CLI\Usage(name: 'scolta:ai-status', description: 'Show the AI provider, key source and cache generation')]
  public function aiStatus(): void {
    $config = $this->configFactory->get('scolta_ui.settings');

    // AI provider. Routing only goes through the Drupal AI module when the
    // admin explicitly selected 'drupal_ai' AND the module is installed —
    // mirror that here instead of reporting on module presence alone.
    $this->logger()->notice('--- AI Provider ---');
    // No coalescing to a provider nobody chose: an empty value means AI is off,
    // and a status command has to report that rather than name Anthropic.
    $provider = $config->get('ai_provider') ?? '';
    if ($provider === '') {
      $this->logger()->notice('  Provider: none selected — AI features are off (search is unaffected)');
    }
    elseif ($provider === 'drupal_ai' && $this->aiService->hasDrupalAiModule()) {
      $this->logger()->notice('  Provider: Drupal AI module');
    }
    elseif ($provider === 'drupal_ai') {
      $this->logger()->notice('  Provider: drupal_ai selected but AI module not installed — falling back to built-in client');
    }
    else {
      $this->logger()->notice("  Provider: {$provider} (built-in)");
    }
    // The source and the description come from the same resolution the client
    // uses, so `status` cannot claim Amazee.ai while an explicit key serves
    // every request (scolta-php#252).
    $resolvedKey = $this->aiService->resolveApiKey();
    $this->logger()->notice("  API key:  {$resolvedKey->source->value}");
    $this->logger()->notice('  ' . $resolvedKey->describe());

    // Generation counter.
    $generation = $this->state->get('scolta.generation', 0);
    $this->logger()->notice("  Cache generation: {$generation}");
  }

}
