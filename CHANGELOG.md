# Changelog

All notable changes to scolta-drupal will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased]

## [0.2.2] - Unreleased

### Added

- **Scoring language:** Settings form now includes a language select (30 ISO 639-1 options) stored as `scoring.language`.
- **Custom stop words:** Textarea field for comma-separated additional stop words (`scoring.custom_stop_words`).
- **Recency strategy:** Select field for recency decay function — `exponential`, `linear`, `step`, `none`, or `custom` (`scoring.recency_strategy`).
- **Custom recency curve:** Textarea for JSON `[[days, boost], …]` control points, visible only when strategy is `custom` (`scoring.recency_curve`).
- Config schema (`scolta.schema.yml`) and install defaults (`scolta.settings.yml`) updated for all four new fields.

## [0.2.1] - 2026-04-15

### Fixed

- **Security:** Validate the configured Pagefind binary path against an allowlist (`pagefind`, `npx`, `node_modules/.bin/pagefind`) before passing to `Process`. Rejects unexpected paths and logs an error, preventing command injection via a compromised config value.

## [0.2.0] - 2026-04-13

### Fixed

- **UX:** `hook_requirements()` added to `scolta.install` — shows a warning in the Drupal Status Report when the Pagefind binary is not installed and the PHP fallback indexer is active, with instructions to install the binary for full language support.

### Added

- **Install hook**: `hook_install()` queues an initial index build on install and displays a status message with instructions for immediate building via Drush.
- **Index-missing validation in search block**: `ScoltaSearchBlock::build()` checks for the Pagefind index on disk; admins see a warning with a link to build, non-admins see nothing until the index is ready.
- **Rebuild Index button**: Admin settings form at `/admin/config/search/scolta` now includes a "Rebuild Index" button that triggers an immediate index rebuild using Batch API (PHP indexer) or synchronous binary execution.
- **Batch API integration**: PHP indexer rebuilds from the admin UI use Drupal's Batch API (`ScoltaBatchOperations`) to process content in chunks, preventing timeouts on large sites.
- **Queue Worker for auto-rebuild**: New `ScoltaRebuildWorker` queue worker (`scolta_rebuild` queue) processes index rebuild requests during cron, with a lock to prevent concurrent builds.
- **Auto-rebuild on entity changes**: `hook_entity_insert()` and `hook_entity_update()` automatically enqueue a rebuild when nodes are saved and `pagefind.auto_rebuild` is enabled.
- **Uninstall cleanup**: `hook_uninstall()` cleans up the rebuild queue, build lock, and state entries when the module is uninstalled.
- **PHP indexer integration**: `scolta:build` now supports in-memory PHP indexing via `Tag1\Scolta\Index\PhpIndexer`, eliminating the need for the Pagefind binary.
- `--indexer` option on `scolta:build` to select indexer mode (`php`, `binary`, or `auto`); overrides the `indexer` config setting.
- `--force` option on `scolta:build` to skip the content fingerprint check and force a rebuild.
- `indexer` config key (`auto`/`php`/`binary`) in `scolta.settings.yml` with matching schema entry.
- Auto-detection: when `indexer` is `auto`, the build command uses the binary if available, otherwise falls back to the PHP indexer.
- Content fingerprint tracking (`.scolta-state` file) to skip unnecessary rebuilds when content has not changed.
- `wasmPath` key added to `drupalSettings.scolta` in `ScoltaSearchBlock`, pointing to the WASM glue JS file served from the module directory.
- `ai_languages` config setting for multilingual AI response support, configurable via the admin form (comma-separated language codes)
- All AI controllers now pass `aiLanguages` from config to `AiEndpointHandler`
- `PromptEnrichEvent` Symfony event dispatched before AI prompts are sent to the LLM provider
- `EventDrivenEnricher` bridging scolta-php's `PromptEnricherInterface` with Drupal's event system
- All AI controllers now inject the event dispatcher and pass the enricher to `AiEndpointHandler`

### Removed

- **Extism/FFI dependency**: All references to `ScoltaWasm`, `ExtismCheck`, `Tag1\Scolta\Wasm`, FFI, and Extism have been removed. scolta-php is now pure PHP with no native extensions required.
- FFI extension removed from CI workflow (`setup-php` no longer requests `ffi`).
- `continue-on-error` removed from CI lint step so lint failures are caught.
- `testScoltaPhpWasmPathUsesUnderscores` test removed (ScoltaWasm.php no longer exists in scolta-php).
- `isExtismAvailable()` helper and associated skip logic removed from `ScoltaSettingsFormTest` since `toJsScoringConfig()` is now pure PHP.

### Changed

- **Client-side WASM scoring**: Scoring, merging, and query expansion parsing now happen entirely in the browser via WASM instead of server-side PHP/WASM. The `wasmPath` setting is injected into `drupalSettings` so `scolta.js` can load the WASM glue module.
- WASM assets (`scolta_core.js`, `scolta_core_bg.wasm`) are now copied to `js/wasm/` by the `copy-assets` composer script alongside the existing JS/CSS assets.
- `scolta:build` Drush command now pre-resolves and caches all prompt templates (Step 3) after building the Pagefind index, reducing runtime overhead for API endpoints.
- Prompt resolution uses pure PHP (`DefaultPrompts::resolve()`) instead of WASM calls.
- Updated PHPDoc comments to remove stale references to WASM/FFI/Extism.

### Previously added

- Search API backend (`ScoltaBackend`) for Pagefind-based indexing and search
- 7 Drush commands: `scolta:build`, `scolta:export`, `scolta:rebuild-index`, `scolta:status`, `scolta:clear-cache`, `scolta:download-pagefind`, `scolta:check-setup`
- Admin settings form at `/admin/config/search/scolta` with AI, scoring, display, cache, and prompt configuration
- Search block (`ScoltaSearchBlock`) for placing the search UI in block regions
- 4 API endpoints: `expand-query`, `summarize`, `followup`, `health` at `/api/scolta/v1/`
- `DrupalCacheDriver` implementing `CacheDriverInterface` for Drupal's cache API
- Content export pipeline integrating Search API indexing with Pagefind HTML generation
- Drupal permissions: "Administer Scolta" and "Use Scolta AI features"
- Config schema and install defaults in `config/schema/` and `config/install/`
- Symlinked shared assets from scolta-php (`scolta.js`, `scolta.css`)
- Drupal behavior bridge (`scolta-drupal-bridge.js`) for Drupal.behaviors integration
