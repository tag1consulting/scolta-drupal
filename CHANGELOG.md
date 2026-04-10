# Changelog

All notable changes to scolta-drupal will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased] (0.2.0-dev)

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

### Added

- `wasmPath` key added to `drupalSettings.scolta` in `ScoltaSearchBlock`, pointing to the WASM glue JS file served from the module directory.
- `ai_languages` config setting for multilingual AI response support, configurable via the admin form (comma-separated language codes)
- All AI controllers now pass `aiLanguages` from config to `AiEndpointHandler`
- `PromptEnrichEvent` Symfony event dispatched before AI prompts are sent to the LLM provider
- `EventDrivenEnricher` bridging scolta-php's `PromptEnricherInterface` with Drupal's event system
- All AI controllers now inject the event dispatcher and pass the enricher to `AiEndpointHandler`

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
