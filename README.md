# Scolta for Drupal

[![CI](https://github.com/tag1consulting/scolta-drupal/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-drupal/actions/workflows/ci.yml)

Drupal 10/11 Search API backend with Drush commands, admin UI, and AI-powered search — built on Pagefind.

## Status

Beta. Scolta is installable and in active use on Drupal sites. The module API documented here will not break within the 0.x minor series without a deprecation notice. Expect breaking changes before 1.0. Test in staging before deploying to production. File bugs at the repo issue tracker.

## What Is Scolta?

Scolta is a scoring, ranking, and AI layer built on [Pagefind](https://pagefind.app/). Pagefind is the search engine: it builds a static inverted index at publish time, runs a browser-side WASM search engine, produces word-position data, and generates highlighted excerpts. Scolta takes Pagefind's result set and re-ranks it with configurable boosts — title match weight, content match weight, recency decay curves, and phrase-proximity multipliers. No search server required. Queries resolve in the visitor's browser against a pre-built static index.

This module is the Drupal adapter. It registers a Search API backend, provides Drush commands for building and maintaining the index, exposes an admin settings form, renders a search block, and offers REST API endpoints for the AI features. The actual scoring, indexing logic, memory management, and AI communication live in [scolta-php](https://github.com/tag1consulting/scolta-php), which this module depends on. Scoring runs client-side via the `scolta.js` browser asset and the pre-built WASM module shipped with scolta-php.

The LLM tier — query expansion, result summarization, follow-up questions — is optional. When enabled, it sends the query text and selected result excerpts to a configured LLM provider (Anthropic, OpenAI, or a self-hosted Ollama endpoint). The base search tier shares nothing with any third party.

## Running Example

The examples in this README and the other Scolta repos use a recipe catalog as the concrete data set. Recipes are a good showcase because recipe vocabulary has genuine cross-dialect mismatches:

- A search for `aubergine parmesan` should surface *Eggplant Parmigiana*.
- A search for `chinese noodle soup` should surface *Lanzhou Beef Noodles*, *Wonton Soup*, and *Dan Dan Noodles*.
- A search for `gluten free pasta` should surface *Zucchini Spaghetti with Pesto* and *Rice Noodle Stir-Fry*.
- A search for `quick dinner under 30 min` should surface *Pad Kra Pao*, *Dan Dan Noodles*, and *Steak Frites*.

Here is how to model this in Drupal and build the index:

**1. Create a `recipe` content type** with fields: `title`, `body` (long text), `field_cuisine` (list/text), `field_diet` (list/text, multi-value), `field_cook_time` (integer).

**2. Add a Search API server** at Admin > Configuration > Search > Search API > Add server. Choose the Scolta backend. Add an index on the Recipe content type.

**3. Index content and build**:

```bash
drush search-api:index && drush scolta:build
```

**4. Place the Scolta Search block** at Admin > Structure > Block Layout. Visit the search page and try:

```
aubergine parmesan
```

Pagefind's stemmer matches the body text where both regional terms appear. Scolta's title boost surfaces *Eggplant Parmigiana* first — the recipe whose title contains the closest match to the query intent.

**5. Try the AI summary** (requires `SCOLTA_API_KEY`). Run the same query and the search page shows a one-paragraph summary drawn from the top results, plus suggested follow-up queries. The AI receives only the query text and the titles/excerpts of the top 5 results.

The recipe fixture HTML files live in [scolta-php](https://github.com/tag1consulting/scolta-php) at `tests/fixtures/recipes/` if you want to run the example outside Drupal.

## Quick Install

```bash
# 1. Install
composer require tag1/scolta-drupal tag1/scolta-php

# 2. Enable
drush en scolta

# 3. Create a Search API server + index with the Scolta backend
#    Admin > Configuration > Search > Search API > Add server
#    Backend: Scolta (re-ranks Pagefind results)

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

With an API key configured, search queries are automatically expanded with related terms, results include an AI summary, and visitors can ask follow-up questions.

## Verify It Works

```bash
drush scolta:check-setup
```

This verifies PHP version, index directories, indexer selection, AI provider configuration, and binary availability. The Drupal Status Report (`/admin/reports/status`) also shows a warning when the Pagefind binary is absent.

```bash
drush scolta:status
```

## What Scolta Replaces (and What It Doesn't)

Scolta is a practical replacement for hosted search SaaS (Algolia, Coveo, SearchStax) and for self-hosted Search API backends like Solr or Elasticsearch when your use case is content search on a Drupal site.

Scolta is not a replacement for:

- Drupal core database search or Drupal's full-text PostgreSQL search — those are fine for small sites and have row-level access control that Scolta does not.
- Solr or Elasticsearch setups with per-document permissions enforced at query time.
- Log analytics or observability pipelines built on Elasticsearch.
- Enterprise search with audit logging, retention policies, or SSO-gated content visibility.

If Solr or Elasticsearch is serving Drupal search with basic full-text queries and no per-document ACL, Scolta is a drop-in replacement that costs less to run. If you need complex access control at the search layer, stay with Solr/Elasticsearch.

## Memory and Scale

The default memory profile is `conservative`, which targets a peak RSS under 96 MB and works on shared hosting with a 128 MB PHP `memory_limit`. Scolta never silently upgrades to a larger profile.

The Drupal admin settings page at Admin > Configuration > Search > Scolta shows the detected `memory_limit` and suggests a profile. The profile selection is always left to the admin.

For the Drush CLI, pass `--memory-budget=<profile|bytes>`:

```bash
drush scolta:build --memory-budget=balanced
```

Available profiles: `conservative` (default, ≤96 MB), `balanced` (≤200 MB), `aggressive` (≤384 MB). Higher budget means fewer, larger index chunks and faster builds.

Tested ceiling at the `conservative` profile: 50,000 pages. Higher counts likely work; not certified yet.

## AI Features and Privacy

Scolta's AI tier is optional. When enabled:

- The LLM receives: the query text, and the titles and excerpts of the top N results (default: 5, configurable via `ai_summary_top_n`).
- The LLM does not receive: the full index contents, full page text, user session data, or visitor identity.
- Which provider receives the query data depends on your `ai_provider` setting: `anthropic`, `openai`, or a self-hosted endpoint via `ai_base_url`.

The base search tier — Pagefind index lookup and Scolta WASM scoring — runs entirely in the visitor's browser with no server-side involvement beyond serving static index files.

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

**Recipe catalog** (no recency, title precision matters):

```yaml
scoring:
  recency_strategy: none
  title_match_boost: 1.5
  title_all_terms_multiplier: 2.0
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
                $event->getResolvedPrompt() . "\n\nFocus on cuisine and dietary information."
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
drush scolta:build --memory-budget=balanced  # Use balanced memory profile
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

The `ScoltaBackend` exports the rendered view of each entity (`entity_view()`), so any field that renders to HTML is included automatically. To add metadata not in the rendered output, implement an event subscriber to modify the export before it is written, or add custom fields to the entity view mode used for indexing.

## Optional Upgrades

### Upgrade to the Pagefind binary indexer

The module auto-selects the PHP indexer on managed hosts. On hosts that support binaries, the Pagefind binary is 5–10× faster. The search experience is identical either way — both indexers produce a Pagefind-compatible index.

```bash
drush scolta:download-pagefind
# or:
npm install -g pagefind
```

Set indexer to "Auto" or "Binary" in the admin settings and rebuild.

The PHP indexer works on WP Engine, Kinsta, Flywheel, Pantheon, and other managed hosts where `exec()` is disabled. It supports 14 languages via Snowball stemming. The Pagefind binary supports 33+ languages and is 5–10× faster, but requires Node.js ≥ 18 or a direct binary download.

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

## Credits

Scolta is built on [Pagefind](https://pagefind.app/) by [CloudCannon](https://cloudcannon.com/). Without Pagefind, Scolta has no search to score — the index format, WASM search engine, word-position data, and excerpt generation are all Pagefind's. Scolta's contribution is the layer that sits on top: configurable scoring, multi-adapter ranking parity, AI features, and platform glue.

## License

GPL-2.0-or-later

## Related Packages

- [scolta-core](https://github.com/tag1consulting/scolta-core) — Rust/WASM scoring, ranking, and AI layer that runs in the browser.
- [scolta-php](https://github.com/tag1consulting/scolta-php) — PHP library that indexes content into Pagefind-compatible indexes, plus the shared orchestration and AI client.
- [scolta-laravel](https://github.com/tag1consulting/scolta-laravel) — Laravel 11/12 package with Artisan commands, a `Searchable` trait for Eloquent models, and a Blade search component.
- [scolta-wp](https://github.com/tag1consulting/scolta-wp) — WordPress 6.x plugin with WP-CLI commands, Settings API page, and a `[scolta_search]` shortcode.
