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
```

To enable AI features (query expansion, summarization, follow-up), set the API key before building:

```bash
export SCOLTA_API_KEY=sk-ant-...
```

Or in `settings.php`:

```php
$settings['scolta.api_key'] = 'sk-ant-...';
```

Then configure AI provider, model, and other options at **Admin > Configuration > Search > Scolta** (`/admin/config/search/scolta`).

## Verify It Works

```bash
drush scolta:check-setup
```

This verifies PHP version, index directories, indexer selection, AI provider configuration, and binary availability. The Drupal Status Report (`/admin/reports/status`) also shows a warning when the Pagefind binary is absent.

Check current index status:

```bash
drush scolta:status
```

## Optional Upgrades

### Upgrade to the Pagefind binary indexer

The module auto-selects the PHP indexer on managed hosts where `exec()` is disabled. On hosts that support binaries, the Pagefind binary is 5–10× faster:

```bash
drush scolta:download-pagefind
# or:
npm install -g pagefind
```

Then set the indexer to "Auto" or "Binary" at **Admin > Configuration > Search > Scolta** and rebuild:

```bash
drush scolta:build
```

See [scolta-php README](../scolta-php/README.md) for a full indexer comparison table.

### Extend indexed content

The `ScoltaBackend` exports the rendered view of each entity (`entity_view()`), so any field that renders to HTML is included automatically. To add metadata not in the rendered output (e.g., product price, custom field), add your field to the entity view mode used for indexing, or implement an event subscriber that modifies the export before it is written.

## Debugging

### "Pagefind binary not found"

On managed hosting where `exec()` is disabled, the module falls back to the PHP indexer automatically. To confirm:

```bash
drush scolta:check-setup
drush scolta:status
```

If you want the binary on a host that supports it:

```bash
drush scolta:download-pagefind
```

### "AI features not working"

1. Verify API key: `drush scolta:check-setup`
2. Clear stale cache: `drush scolta:clear-cache`
3. Clear Drupal cache: `drush cr`
4. Confirm the model name is current at **Admin > Configuration > Search > Scolta**

### "No search results"

1. Check index status: `drush scolta:status`
2. Run a full rebuild: `drush search-api:index && drush scolta:build`
3. Confirm the Pagefind output directory is web-accessible (must be under `public://`)
4. Verify the Search API index has the Scolta backend selected and is enabled

### "Config schema validation errors after update"

Run `drush config:status` to check for mismatches. If the install config differs from the schema, export and re-import:

```bash
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
|--------|------|------------|-------------|
| POST | `/api/scolta/v1/expand-query` | `use scolta ai` | Expand a search query into related terms |
| POST | `/api/scolta/v1/summarize` | `use scolta ai` | Summarize search results |
| POST | `/api/scolta/v1/followup` | `use scolta ai` | Continue a search conversation |

Grant the `use scolta ai` permission to the Anonymous role for public search.

## Permissions

- **Administer Scolta** — Access the settings form
- **Use Scolta AI features** — Access the AI endpoints

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

**PHPStan** (requires DDEV for Drupal class resolution):

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
