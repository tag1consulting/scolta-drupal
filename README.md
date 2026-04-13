# Scolta for Drupal

[![CI](https://github.com/tag1consulting/scolta-drupal/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-drupal/actions/workflows/ci.yml)

Scolta adds AI-powered search to your Drupal site. Search runs entirely in the browser using Pagefind — no search server needed. Optional AI features handle query expansion, result summarization, and follow-up conversations. Works with any content type, any language.

## Quickstart

```bash
# 1. Install
composer require tag1/scolta-drupal tag1/scolta-php

# 2. Enable the module
drush en scolta

# 3. Verify prerequisites
drush scolta:check-setup

# 4. Create a Search API server + index with the Scolta backend
#    (Admin > Configuration > Search > Search API)

# 5. Index content and build the search index
drush search-api:index && drush scolta:build

# 6. Place the Scolta Search block — you have AI search.
```

## Configuration

Set the API key to enable AI features:

```bash
export SCOLTA_API_KEY=sk-ant-...
```

Then configure AI settings at **Admin > Configuration > Search > Scolta** (`/admin/config/search/scolta`):

- **AI Configuration** -- Provider, model, feature toggles, follow-up limits
- **Scoring** -- Title/content match boosts, recency decay, expanded term weights
- **Display** -- Excerpt length, results per page, AI summary parameters
- **Cache** -- TTL for AI response caching

See [CONFIG_REFERENCE.md](../../docs/CONFIG_REFERENCE.md) for the full list of settings.

## Prompt Enrichment

The built-in expand, summarize, and follow-up prompts can be customized via the settings form under **Custom Prompts**. You can also set site name and description to give the AI better context about your content. See [ENRICHMENT.md](../../docs/ENRICHMENT.md) for details on prompt customization.

## How It Works

1. **Indexing** -- Search API indexes content through the Scolta backend, which exports each item as an HTML file with Pagefind data attributes, then runs the Pagefind CLI to build a static search index.
2. **Search** -- Entirely client-side. The browser loads `pagefind.js`, searches the static index, and scolta.js handles scoring, filtering, and result rendering.
3. **AI features** -- Optional. When an API key is configured, the module provides server-side endpoints for query expansion, result summarization, and follow-up conversations powered by Anthropic or OpenAI.

## Architecture

Scolta is a multi-package system. This Drupal module is a platform adapter that sits on top of the shared PHP library:

```
scolta-drupal (this module)        scolta-php              scolta-core (WASM)
  ScoltaBackend ─────────────> ContentExporter ──────> cleanHtml()
  ScoltaAiService ───────────> AiClient                buildPagefindHtml()
  ScoltaSettingsForm ────────> ScoltaConfig ─────────> toJsScoringConfig()
  ScoltaSearchBlock ─────────> DefaultPrompts ───────> resolvePrompt()
  ScoltaCommands ────────────> PagefindBinary           scoreResults()
  DrupalCacheDriver ─────────> CacheDriverInterface     mergeResults()
```

The Drupal module handles CMS-specific concerns: Search API integration, Drush commands, admin forms, block plugins, routing, and permissions. All scoring, HTML processing, and prompt logic lives in the WASM module, accessed through scolta-php. This module never depends on scolta-core directly.

## Requirements

- Drupal 10.3+ or 11
- Search API module (`drupal/search_api`)
- PHP 8.1+
- Pagefind CLI (`npm install -g pagefind`) — optional, see Indexer section below

## Installation

```bash
composer require tag1/scolta-drupal tag1/scolta-php
drush en scolta
```

## Setup

### 1. Create a Search API Server

Go to **Admin > Configuration > Search > Search API** (`/admin/config/search/search-api`) and add a new server:

- **Backend**: Scolta (Pagefind)
- **Build directory**: `private://scolta-build`
- **Output directory**: `public://scolta-pagefind`

### 2. Create a Search API Index

Create an index on the Scolta server. Add the content types and fields you want indexed.

### 3. Index Content

```bash
drush search-api:index
```

Or let Search API cron handle it.

### 4. Place the Search Block

Go to **Admin > Structure > Block Layout** and place the **Scolta Search** block in your desired region.

### 5. Configure AI (Optional)

Set the API key via environment variable (recommended):

```bash
export SCOLTA_API_KEY=sk-ant-...
```

Or in `settings.php`:

```php
$settings['scolta.api_key'] = 'sk-ant-...';
```

Then configure AI settings at **Admin > Configuration > Search > Scolta** (`/admin/config/search/scolta`).

## Verify Your Setup

After installation, run the setup check to verify all prerequisites:

```bash
drush scolta:check-setup
```

This verifies PHP version, Pagefind binary, AI provider configuration, and cache backend. Fix any items marked as failed before proceeding.

## Configuration Details

The settings form at `/admin/config/search/scolta` provides:

- **AI Configuration** -- Provider, model, feature toggles, follow-up limits
- **Content** -- Site name and description for AI prompt context
- **Scoring** -- Title/content match boosts, recency decay, expanded term weights
- **Display** -- Excerpt length, results per page, AI summary parameters
- **Cache** -- TTL for AI response caching
- **Custom Prompts** -- Override the built-in expand, summarize, and follow-up prompts
- **Status** -- AI provider, Pagefind binary, index status, Search API index

## API Endpoints

| Method | Path | Permission | Description |
|--------|------|------------|-------------|
| POST | `/api/scolta/v1/expand-query` | `use scolta ai` | Expand a search query into related terms |
| POST | `/api/scolta/v1/summarize` | `use scolta ai` | Summarize search results |
| POST | `/api/scolta/v1/followup` | `use scolta ai` | Continue a search conversation |

## Drush Commands

```bash
drush scolta:build                    # Export content and build Pagefind index
drush scolta:build --skip-pagefind    # Export HTML without rebuilding index
drush scolta:export                   # Export content to HTML only
drush scolta:rebuild-index            # Rebuild Pagefind index from existing HTML
drush scolta:status                   # Show tracker, content, index, and AI status
drush scolta:clear-cache              # Clear Scolta AI response caches
drush scolta:download-pagefind        # Download the Pagefind binary
drush scolta:check-setup              # Verify PHP, Pagefind, and configuration
```

## Content Coverage

Scolta indexes content through Search API. What gets indexed depends on which entity types, bundles, and fields you add to your Search API index.

### What gets indexed

- **Any Search API datasource** -- nodes, taxonomy terms, users, Commerce products, or custom entities added to the index.
- **Rendered HTML** -- Scolta exports the rendered view of each item (via `entity_view()`). Body fields, paragraph fields, layout builder regions, and any other fields that render to HTML are included automatically.
- **Title** -- sanitized and tokenized for search.
- **URL and date** -- used for display and recency scoring.

### What is NOT indexed by default

- Fields not added to the Search API index.
- Fields added to the index as raw values but not rendered through the entity view.
- Content in blocks or sidebars outside the entity view.

### Extending with a custom ContentItem

The `ScoltaBackend` passes each indexed item through `PagefindExporter`, which builds a `ContentItem` from the rendered entity. To add metadata not present in the rendered output (e.g., product price, custom field), implement a Drupal event subscriber or alter hook and modify the export before it is written.

Alternatively, add your custom field to the entity view mode used for indexing so it renders into the HTML that Scolta exports.

## Permissions

- **Administer Scolta** -- Access the settings form
- **Use Scolta AI features** -- Access the AI endpoints (grant to anonymous for public search)

## Module Structure

```
src/
  Commands/ScoltaCommands.php         # Drush commands
  Controller/ExpandQueryController.php    # AI query expansion endpoint
  Controller/FollowUpController.php       # AI follow-up endpoint
  Controller/SummarizeController.php      # AI summarization endpoint
  Form/ScoltaSettingsForm.php             # Admin settings form
  Plugin/Block/ScoltaSearchBlock.php      # Search UI block
  Plugin/search_api/backend/ScoltaBackend.php  # Search API backend
  Service/PagefindBuilder.php             # Pagefind CLI orchestration
  Service/PagefindExporter.php            # Content-to-HTML export
  Service/ScoltaAiService.php             # AI service wrapper
js/
  scolta.js -> ../../scolta-php/assets/js/scolta.js     # Shared search UI
  scolta-drupal-bridge.js                                # Drupal behavior bridge
css/
  scolta.css -> ../../scolta-php/assets/css/scolta.css  # Shared search styles
config/
  install/scolta.settings.yml       # Default configuration
  schema/scolta.schema.yml          # Config schema
```

## Testing

**Unit tests** (fast, no CMS required -- 329 tests):

```bash
cd packages/scolta-drupal
./vendor/bin/phpunit
```

**Functional tests** (requires DDEV -- 19 tests):

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

**PHPStan** (requires DDEV for Drupal class resolution):

```bash
cd test-drupal-11
ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

## Indexer

Scolta auto-detects the best available indexer (`indexer: auto` default). See [scolta-php README](../scolta-php/README.md) for the full comparison table.

| Feature | PHP Indexer | Pagefind Binary |
| ------- | ----------- | --------------- |
| Languages with stemming | 15 (Snowball) | 33+ |
| Speed (1 000 pages) | ~3–4 seconds | ~0.3–0.5 seconds |
| Shared / managed hosting | Yes | Only if binary installable |

**To upgrade to the binary indexer:**

```bash
npm install -g pagefind
# or:
drush scolta:download-pagefind
```

Verify: `drush scolta:check-setup` — the Drupal Status Report also shows a warning when the binary is absent.

## Hosting

See the [Scolta Hosting Guide](../scolta-php/HOSTING.md) for platform-specific
deployment guidance, indexer selection, and ephemeral filesystem handling.

## Troubleshooting

### "Pagefind binary not found"

```bash
drush scolta:download-pagefind
# or
npm install -g pagefind
```

### "AI features not working"

1. Verify API key: `drush scolta:check-setup`
2. Clear stale cache: `drush scolta:clear-cache`
3. Also clear Drupal cache: `drush cr`

### "No search results"

1. Check index status: `drush scolta:status`
2. Run a full build: `drush search-api:index && drush scolta:build`
3. Verify the Pagefind output directory is web-accessible

## License

GPL-2.0-or-later
