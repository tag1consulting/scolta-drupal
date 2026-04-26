# Changelog

All notable changes to scolta-drupal will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased]

### Changed
- **`buildWithPhpIndexer()` budget and intent construction**: Delegated to `MemoryBudgetConfig::fromCliAndConfig()` and `BuildIntentFactory::fromFlags()` (scolta-php), removing duplicated precedence logic.
- **`ScoltaContentGatherer::gather()` batch size**: Increased from 50 to 100 entities per page-load, consistent with the WP and Laravel adapters.
- **`DrushProgressReporter::advance()`**: Now calls `setMessage($detail)` on the Symfony ProgressBar when a detail string is provided, making chunk info visible in verbose Drush output.
- **`ExpandQueryController`, `SummarizeController`, `FollowUpController`**: Now use `AiControllerTrait` (scolta-php) for `AiEndpointHandler` construction, removing the duplicated 7-argument instantiation block from each controller.
- **Anti-pattern CI check.** New `antipatterns` CI job catches `IndexBuildOrchestrator` construction without logger/progress.
- **scolta-php dependency bumped to `^0.3.3`** (atomic manifest writes, CRC32 chunk validation, stale lock detection).

## [0.3.2] - 2026-04-24

Coordinated release. Ports the streaming gather and CLI wiring pattern from scolta-wp to Drupal.

### Fixed
- **Silent CLI during large builds**: `buildWithPhpIndexer()` was passing neither a logger nor a progress reporter to `IndexBuildOrchestrator::build()`. Added `DrushProgressReporter` (wraps Symfony `ProgressBar` via Drush's output interface) and now passes `$this->logger()` (Drush's built-in PSR-3 logger) to `build()`. (#7)
- **Peak RAM on large corpora**: `ScoltaContentGatherer::gather()` converted from a fully-materialized `ContentItem[]` (loading all entity IDs then `loadMultiple()` on all of them) to a `\Generator` that paginates with `->range()` in batches of 50 and calls `$storage->resetCache()` after each batch. The old code held all entity field data in RAM simultaneously; the new code holds at most one batch. (#7)
- **Lint**: Removed unused `MemoryBudgetConfig` import in `ScoltaCommands.php`. Fixed alignment, missing use statement, and missing `@return` description in `MemoryBudgetSettingsFieldSet.php`. (#7)

### Added
- **Flexible memory budget and chunk size**: `drush scolta:build` now accepts `--memory-budget=<budget>` with profile names *or* raw byte values (`256M`, `1G`), and a new `--chunk-size=<n>` flag to set pages-per-chunk independently of the memory profile. Both values are persisted as admin settings (`memory_budget.profile` and `memory_budget.chunk_size`). The settings form Memory Budget field is now a text input (with datalist suggestions) and a new Chunk Size number field has been added. Config schema and install defaults updated.
- **`ScoltaContentGatherer::gatherCount(string $entityType, string $bundle): int`**: COUNT-only entity query. Used by `buildWithPhpIndexer()` for early-exit and `BuildIntent` sizing without loading entity field data. (#7)

### Changed
- CI now pulls scolta-php at `@dev` rather than the stale `consolidation-0.3.0` branch.

## [0.3.1] - 2026-04-23

### Fixed
- **Release packaging**: Release workflow now triggers on both `v0.x.x` and bare `0.x.x` tag formats, fixing the 0.3.0 release that shipped with no binary assets.

### Added
- **Zip structure regression test**: New `validate-zip` CI job asserts `scolta-drupal/vendor/autoload.php` and `scolta-drupal/scolta.module` are present in each release archive.
- **Memory budget profile fieldset**: Settings form (Content section) now includes a Memory Budget details element. Explains that the budget is advisory within the existing PHP `memory_limit`, shows the current limit inline, and warns when the selected profile's target RAM exceeds 70% of the detected limit. `drush scolta:build` reads the saved profile as the default for `--memory-budget`.

## [0.3.0] - 2026-04-23

### Added
- **`--memory-budget` option**: Pass `conservative` (default), `balanced`, or `aggressive` to `drush scolta:build`.
- **`--resume` option**: Resume a previously interrupted PHP index build.
- **`--restart` option**: Discard interrupted state and force a clean rebuild.

### Changed
- **`buildWithPhpIndexer()`** rewritten to use `IndexBuildOrchestrator::build()` — 85 lines down to ~30.
- Path resolution logic extracted to `resolvePath()` private helper.
- Inherits all scolta-php 0.3.0 improvements: `MemoryBudget`, `BuildIntent`, `BuildCoordinator`, streaming pipeline, OOM fix.

### Fixed
- **Status command indexer section**: `drush scolta:status` now shows `--- Indexer ---` (was `--- Pagefind Binary ---`) with active indexer selection logic matching the Laravel/WP adapters.

## [0.2.4] - 2026-04-21

### Added
- **Playwright layout tests** (`tests/playwright/layout.spec.js`): Three browser-level tests at 1440 px viewport asserting `.scolta-layout` fills ≥90 % of viewport width in single-column and two-column (`has-filters`) modes. Wired into CI (`playwright` job in `.github/workflows/ci.yml`).
- **Admin rebuild notice persistence**: Rebuild notices now persist in Drupal State across page loads until each admin user explicitly dismisses them. Per-user dismissal tracked via `user.data` service keyed to a unique `notice_id`; notices render via `hook_page_top()` on admin pages. Dismiss route: `GET /admin/config/search/scolta/dismiss-rebuild-notice?notice_id=…`.
- **Drupal functional test suite in CI** (`functional` job in `.github/workflows/ci.yml`): Boots a real Drupal 11 installation (SQLite + PHP built-in server) and runs all `tests/src/Functional/` tests on every push. Covers the full HTTP render pipeline, including `hook_page_top()` and controller instantiation, which unit tests cannot reach.
- **`RouteSmokeFunctionalTest`**: Reads `scolta.routing.yml` at runtime and smoke-tests every defined route — GET routes as authenticated admin (assert non-500), GET routes as anonymous (assert 302/403), POST routes with empty body (assert structured JSON 4xx). Any route added to the YAML is automatically covered on the next CI run without a manual test-list update.
- **`YamlIntegrityTest::testAllFromRouteCallsReferenceDefinedRoutes`**: Static-analysis guard that scans all `.module` and `src/` PHP files for `fromRoute('scolta.*')` calls and asserts each name exists in `scolta.routing.yml`. Catches the `RouteNotFoundException` class of bug at the unit-test level before a browser ever hits the page.

### Changed
- Inherits all scolta-php 0.2.4 fixes and features (phrase-proximity scoring, WASM config key fix, quoted-phrase forced-mode, second WASM rebuild)

### Fixed
- **Full-width search results layout**: `css/scolta.css` had `grid-template-columns: 220px minmax(0, 1fr)` as the permanent default for `.scolta-layout`, making the empty filter sidebar always occupy 220px and squeezing all results into the narrow right column. The layout now defaults to `grid-template-columns: 1fr`; the two-column variant only activates via `.scolta-layout.has-filters` (added by JS when multiple sites are indexed). Added `.scolta-filters:empty { display: none }` so the empty sidebar is hidden. Adds `LayoutCssRegressionTest` to guard against recurrence.

## [0.2.3] - 2026-04-17

### Fixed
- **Asset deployment on install**: `hook_install()` now copies compiled JS/CSS/WASM from `scolta-php` into the module directory. Composer `post-install-cmd` scripts only run for the root package, so assets were never deployed when installing as a dependency.
- **Pagefind path fix**: `ScoltaSearchBlock` was pointing `pagefindPath` at `{output_dir}/pagefind.js`; the Pagefind binary writes its output into a `pagefind/` subdirectory, so the correct path is `{output_dir}/pagefind/pagefind.js`.
- **Cache invalidation after rebuild**: All rebuild paths (Drush, admin UI form, Batch API, Queue Worker) now invalidate the `scolta_search_index` cache tag so the search block updates immediately without a manual cache flush.

### Changed
- Inherits all scolta-php 0.2.3 fixes and features (filter sidebar, N-set merge, AI context, PII sanitization, priority pages)

## [0.2.2] - 2026-04-16

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
