# Scolta for Drupal

[![CI](https://github.com/tag1consulting/scolta-drupal/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-drupal/actions/workflows/ci.yml)

Scolta is a browser-side search engine: the index lives in static files, scoring runs in the browser via WebAssembly, and an optional AI layer handles query expansion and summarization. No search server required. "Scolta" is archaic Italian for sentinel — someone watching for what matters.

This module is the Drupal adapter. It provides a Search API backend, Drush commands, an admin settings form, a search block, and REST API endpoints.

## Quick Install

```bash
# 1. Install
composer require tag1/scolta-drupal tag1/scolta-php

# 2. Enable
drush en scolta

# 3. Create a Search API server + index with the Scolta backend
#    Admin > Configuration > Search > Search API > Add server
#    Backend: Scolta (Pagefind)

# 4. Index content and build the search index
drush search-api:index && drush scolta:build

# 5. Place the Scolta Search block
#    Admin > Structure > Block Layout

# 6. Set your API key to unlock AI features
```

Add to your environment or `settings.php`:

```bash
export SCOLTA_API_KEY=sk-ant-...
```

```php
// settings.php
$settings['scolta.api_key'] = 'sk-ant-...';
```

With an API key configured, search queries are automatically expanded with related terms, results include an AI summary, and users can ask follow-up questions.

## Verify It Works

```bash
drush scolta:check-setup
```

This verifies PHP version, index directories, indexer selection, AI provider configuration, and binary availability. The Drupal Status Report (`/admin/reports/status`) also shows a warning when the Pagefind binary is absent.

```bash
drush scolta:status
```

## Configuration

### AI Provider

Configure at **Admin > Configuration > Search > Scolta** (`/admin/config/search/scolta`), or via `scolta.settings.yml` / `config/sync`.

| Setting | Config key | Default | Description |
| ------- | ---------- | ------- | ----------- |
| Provider | `ai_provider` | `anthropic` | `anthropic` or `openai` |
| API key | env/settings.php only | — | `SCOLTA_API_KEY` env var or `$settings['scolta.api_key']` |
| Model | `ai_model` | `claude-sonnet-4-5-20250929` | LLM model identifier |
| Base URL | `ai_base_url` | provider default | Custom endpoint for proxies or Azure OpenAI |
| Query expansion | `ai_expand_query` | `true` | Toggle AI query expansion on/off |
| Summarization | `ai_summarize` | `true` | Toggle AI result summarization on/off |
| Summary top N | `ai_summary_top_n` | `5` | How many top results to send to AI for summarization |
| Summary max chars | `ai_summary_max_chars` | `2000` | Max content characters sent to AI per request |
| Max follow-ups | `max_follow_ups` | `3` | Follow-up questions allowed per session |
| AI languages | `ai_languages` | `['en']` | Languages the AI responds in (matches user query language) |

In `config/sync/scolta.settings.yml`:

```yaml
ai_provider: anthropic
ai_model: claude-sonnet-4-5-20250929
ai_expand_query: true
ai_summarize: true
ai_summary_top_n: 5
ai_summary_max_chars: 2000
max_follow_ups: 3
ai_languages:
  - en
```

For multilingual sites:

```yaml
ai_languages:
  - en
  - fr
  - de
```

### Search Scoring

Configure at **Admin > Configuration > Search > Scolta**, or in `scolta.settings.yml` under the `scoring` key.

| Setting | Config key | Default | Description |
| ------- | ---------- | ------- | ----------- |
| Title match boost | `scoring.title_match_boost` | `1.0` | Boost when query terms appear in the title |
| Title all-terms multiplier | `scoring.title_all_terms_multiplier` | `1.5` | Extra multiplier when ALL terms match the title |
| Content match boost | `scoring.content_match_boost` | `0.4` | Boost for query term matches in body/excerpt |
| Expand primary weight | `scoring.expand_primary_weight` | `0.7` | Weight for original query results vs AI-expanded results |
| Recency strategy | `scoring.recency_strategy` | `exponential` | `exponential`, `linear`, `step`, `none`, or `custom` |
| Recency boost max | `scoring.recency_boost_max` | `0.5` | Maximum positive boost for very recent content |
| Recency half-life days | `scoring.recency_half_life_days` | `365` | Days until recency boost halves |
| Recency penalty after days | `scoring.recency_penalty_after_days` | `1825` | Age before content gets a penalty (~5 years) |
| Recency max penalty | `scoring.recency_max_penalty` | `0.3` | Maximum negative penalty for very old content |
| Language | `scoring.language` | `en` | ISO 639-1 code for stop word filtering |
| Custom stop words | `scoring.custom_stop_words` | `[]` | Extra stop words beyond the language's built-in list |

**News site** (recency matters a lot):

```yaml
# scolta.settings.yml
scoring:
  recency_boost_max: 0.8
  recency_half_life_days: 30
  recency_penalty_after_days: 365
  recency_max_penalty: 0.5
```

**Documentation site** (recency doesn't matter, titles matter a lot):

```yaml
scoring:
  recency_strategy: none
  title_match_boost: 2.0
  title_all_terms_multiplier: 2.5
```

### Display

| Setting | Config key | Default | Description |
| ------- | ---------- | ------- | ----------- |
| Excerpt length | `excerpt_length` | `300` | Characters shown in result excerpts |
| Results per page | `results_per_page` | `10` | Results shown per page |
| Max Pagefind results | `max_pagefind_results` | `50` | Total results fetched from index before scoring |

### Site Identity

| Setting | Config key | Default | Description |
| ------- | ---------- | ------- | ----------- |
| Site name | `site_name` | (empty) | Included in AI prompts so the AI knows what site it's searching |
| Site description | `site_description` | `website` | Brief description for AI context |

The AI uses your site name and description to give contextually relevant answers. A search on "pricing" will produce very different AI summaries on a SaaS product site vs. a news outlet.

### Custom Prompts

Override the built-in AI prompts at **Admin > Configuration > Search > Scolta > Custom Prompts**, or use a Symfony event subscriber in your module:

```php
// my_module/src/EventSubscriber/PromptEnrichSubscriber.php
use Drupal\scolta\Event\PromptEnrichEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PromptEnrichSubscriber implements EventSubscriberInterface {
    public static function getSubscribedEvents(): array {
        return [PromptEnrichEvent::class => 'onPromptEnrich'];
    }

    public function onPromptEnrich(PromptEnrichEvent $event): void {
        if ($event->getPromptName() === 'summarize') {
            $event->setResolvedPrompt(
                $event->getResolvedPrompt() . "\n\nAlways mention our 30-day return policy."
            );
        }
    }
}
```

Register it in `my_module.services.yml`:

```yaml
services:
  my_module.prompt_enrich_subscriber:
    class: Drupal\my_module\EventSubscriber\PromptEnrichSubscriber
    tags:
      - { name: event_subscriber }
```

See [ENRICHMENT.md](../../packages/scolta-php/docs/ENRICHMENT.md) for advanced use cases (vertical examples, multi-tenant, compliance).

## Debugging

### "Pagefind binary not found"

On managed hosting where `exec()` is disabled, the module falls back to the PHP indexer automatically:

```bash
drush scolta:check-setup
drush scolta:status
```

To install the binary on a host that supports it:

```bash
drush scolta:download-pagefind
```

### "AI features not working"

1. Verify API key: `drush scolta:check-setup`
2. Clear stale cache: `drush scolta:clear-cache`
3. Clear Drupal cache: `drush cr`
4. Confirm the model name at **Admin > Configuration > Search > Scolta**

### "AI summary says 'I don't have enough context'"

Increase how much content is sent to the AI. In `scolta.settings.yml`:

```yaml
ai_summary_top_n: 10
ai_summary_max_chars: 4000
```

### "AI responses are in the wrong language"

Set `ai_languages` to match your site's language(s):

```yaml
ai_languages:
  - de
```

Or for multilingual: `ai_languages: [en, fr, de]`

### "Expanded queries return irrelevant results"

Lower `expand_primary_weight` to give more weight to the original query, or disable expansion:

```yaml
scoring:
  expand_primary_weight: 0.9
# or: ai_expand_query: false
```

### "AI features are slow"

Check which model is configured — smaller models respond faster. Verify cache TTL is not set too low (default 30 days means expansions are cached for 30 days once computed).

### "No search results"

1. Check index status: `drush scolta:status`
2. Run a full rebuild: `drush search-api:index && drush scolta:build`
3. Confirm the Pagefind output directory is web-accessible (must be under `public://`)
4. Verify the Search API index has the Scolta backend selected and is enabled

### "Config schema validation errors after update"

```bash
drush config:status
drush config:export && drush config:import
```

## Drush Commands

```bash
drush scolta:build                    # Export content and build Pagefind index
drush scolta:build --skip-pagefind    # Export HTML without rebuilding index
drush scolta:export                   # Export content to HTML only
drush scolta:rebuild-index            # Rebuild Pagefind index from existing HTML
drush scolta:status                   # Show tracker, content, index, and AI status
drush scolta:clear-cache              # Clear Scolta AI response caches
drush scolta:download-pagefind        # Download the Pagefind binary for your platform
drush scolta:check-setup              # Verify PHP, indexer, and configuration
```

## API Endpoints

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| POST | `/api/scolta/v1/expand-query` | `use scolta ai` | Expand a search query into related terms |
| POST | `/api/scolta/v1/summarize` | `use scolta ai` | Summarize search results |
| POST | `/api/scolta/v1/followup` | `use scolta ai` | Continue a search conversation |

Grant the `use scolta ai` permission to the Anonymous role for public search.

## Permissions

- **Administer Scolta** — Access the settings form
- **Use Scolta AI features** — Access the AI endpoints

## Extend Indexed Content

The `ScoltaBackend` exports the rendered view of each entity (`entity_view()`), so any field that renders to HTML is included automatically. To add metadata not in the rendered output, add your field to the entity view mode used for indexing, or implement an event subscriber to modify the export before it is written.

## Optional Upgrades

### Upgrade to the Pagefind binary indexer

The module auto-selects the PHP indexer on managed hosts. On hosts that support binaries, the Pagefind binary is 5–10× faster:

```bash
drush scolta:download-pagefind
# or:
npm install -g pagefind
```

Set indexer to "Auto" or "Binary" in the admin settings and rebuild. See [scolta-php README](../scolta-php/README.md) for a full indexer comparison table.

## Requirements

- Drupal 10.3+ or 11
- Search API module (`drupal/search_api`)
- PHP 8.1+

The Pagefind binary is optional — the PHP indexer works without it.

## Testing

**Unit tests** (no Drupal bootstrap required):

```bash
cd packages/scolta-drupal
./vendor/bin/phpunit
```

**Functional tests** (requires DDEV):

```bash
cd test-drupal-11
ddev exec php vendor/bin/phpunit --testsuite=scolta-functional
```

**Coding standards:**

```bash
cd packages/scolta-drupal
composer lint    # PHPCS (Drupal + DrupalPractice)
composer format  # Auto-fix violations
```

**PHPStan** (requires DDEV):

```bash
cd test-drupal-11
ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

## Architecture

```text
scolta-drupal (this module)        scolta-php              scolta-core (browser WASM)
  ScoltaBackend ─────────────> ContentExporter ──────> cleanHtml()
  ScoltaAiService ───────────> AiClient                buildPagefindHtml()
  ScoltaSettingsForm ────────> ScoltaConfig
  ScoltaSearchBlock ─────────> DefaultPrompts            (runs in browser)
  ScoltaCommands ────────────> PagefindBinary            scoreResults()
  DrupalCacheDriver ─────────> CacheDriverInterface      mergeResults()
```

This module handles Drupal-specific concerns: Search API integration, Drush commands, admin forms, block plugins, routing, and permissions. It depends on scolta-php and never on scolta-core directly. Scoring runs client-side via WebAssembly loaded by `scolta.js`.

```text
src/
  Commands/ScoltaCommands.php              Drush commands
  Controller/ExpandQueryController.php     AI query expansion endpoint
  Controller/FollowUpController.php        AI follow-up endpoint
  Controller/SummarizeController.php       AI summarization endpoint
  Form/ScoltaSettingsForm.php              Admin settings form
  Plugin/Block/ScoltaSearchBlock.php       Search UI block
  Plugin/search_api/backend/ScoltaBackend.php  Search API backend
  Service/PagefindBuilder.php              Pagefind CLI orchestration
  Service/PagefindExporter.php             Content-to-HTML export
  Service/ScoltaAiService.php              AI service wrapper
config/
  install/scolta.settings.yml              Default configuration
  schema/scolta.schema.yml                 Config schema
```

## License

GPL-2.0-or-later
