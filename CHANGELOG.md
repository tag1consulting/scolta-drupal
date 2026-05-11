# Changelog

All notable changes to scolta-drupal will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [1.0.0] Unreleased

First stable release — all features from 0.3.x promoted to 1.0 API surface.

### Changed
- Added `extra.branch-alias` (`dev-main` → `1.0.x-dev`) so consumers can resolve this package with `^1.0@dev` from a VCS repository.

## [Unreleased]

### Fixed
- **Pagefind WASM no longer crashes with "No pointer" on multilingual language switch.** `initPagefind()` now uses a module-level `pagefindInstance` guard so `pagefind.init()` is called only once per page. Previously, Drupal behavior re-attachment on language switch caused a second `init()` call on Pagefind's SharedWorker, permanently corrupting the WASM pointer for the tab.
- **Summarize no longer returns HTTP 400 with large result sets.** `summarizeResults()` now truncates the context string to 49,000 characters before sending to the summarize endpoint. Previously, 300+ search results could produce a context blob exceeding the server's limit, causing HTTP 400 with no useful error shown to the user.
- **Settings page now correctly shows "Connected to Amazee.ai" status** when auto-provisioning has run. Previously `buildApiKeyStatus()` had no `'amazee'` case, so it fell through to the "No API key configured" warning even when Amazee credentials were active. The status now links to the Amazee.ai configuration page.

### Added
- **Amazee.ai trial is provisioned automatically at module install time.** `scolta_install()` calls `_scolta_auto_provision_amazee()` after asset deployment. The helper uses `AutoProvisioner::ensureAiAvailable()` from scolta-php with the `scolta.amazee_config_storage` service. It is a no-op when a `SCOLTA_API_KEY` env var or `scolta.api_key` in settings.php is already configured, or when credentials are already stored. On success, the `ai_model` and `ai_expansion_model` config keys are updated via the `onModelsResolved` callback. `ScoltaAiService::createClient()` also attempts lazy provisioning on the first AI request as a fallback for install environments without network access; credentials are refreshed by calling `buildConfig()` (now `protected`) after provisioning.

- **`ScoltaSearchBlock` now passes the current content language to the JS frontend.** `LanguageManager::getCurrentLanguage(TYPE_CONTENT)->getId()` is called at render time and passed to `drupalSettings.scolta.currentLanguage`. On multilingual sites using URL-prefix negotiation, `/es/` pages receive `currentLanguage: 'es'` and `/fr/` pages receive `currentLanguage: 'fr'`. The JS frontend (scolta-php) uses this to pre-filter search results to the page language; users can uncheck the language facet to search all languages. Requires scolta-php with auto-language-filter support.

- **Search API "Manage fields" form now shows a help notice when Scolta is the backend.** Field configuration has no effect on Scolta indexing — Scolta indexes the full rendered entity view, not individual Search API fields. Admins were spending time configuring fields that were silently ignored. A `hook_form_FORM_ID_alter` implementation now injects an info notice at the top of the fields form when the index uses the `scolta_pagefind` backend, explaining that field selection is not required. ([#42](https://github.com/tag1consulting/scolta-drupal/issues/42))

### Fixed
- **"Index Now" no longer times out on shared hosting for non-trivial corpora.** `rebuildSubmit()` was calling `gatherContentItems()` which called `loadMultiple()` on all published nodes synchronously within the web request, exhausting memory and hitting the request timeout before any Batch API work was dispatched. The PHP indexer path now queries only entity IDs in the submit handler (a lightweight DB call) and defers all entity loading, content extraction, and filtering to `ScoltaBatchOperations::loadAndProcessChunk()` — a new static batch callback that loads one chunk of entities per batch step. The binary indexer path is unchanged (binary mode requires `exec()` and is not used on shared hosting). Also fixes a `setAbsolute(TRUE)` URL bug in `gatherContentItems()` that was causing doubled paths on subdirectory Drupal installs (same root cause as #40). ([#37](https://github.com/tag1consulting/scolta-drupal/issues/37))

### Documentation
- **Added shared hosting and large-corpus guidance.** README now covers SSH disconnect resilience (`nohup`, `screen`, `tmux`), when to use `drush scolta:build` instead of `drush search-api:index` for initial/full builds, the `--resume` and `--restart` flags for recovering interrupted builds, and `drush scolta:finalize` for deferred-merge scenarios on very large corpora. The Drush commands table now lists all build flags (`--resume`, `--restart`, `--force`, `--chunk-size`, `--indexer`). The "No search results" debugging tip no longer recommends `drush search-api:index` for a full rebuild. ([#38](https://github.com/tag1consulting/scolta-drupal/issues/38))

### Tests
- **Delegation tests added for `getDefaultPrompt()`.** New assertions in `ScoltaSettingsFormTest` verify that `ScoltaSettingsForm::getDefaultPrompt()` delegates to `DefaultPrompts::getTemplate()` (no inline prompt copies) and that resolved prompts match `DefaultPrompts::resolve()` output for all three template keys. Fixes #49 (Drupal side).

### Fixed
- **`site_name` now falls back to the Drupal system site name when not explicitly configured.** Four code paths in the index-building pipeline — `ScoltaRebuildWorker::processItem()`, `ScoltaCommands::export()`, `ScoltaCommands::buildWithPhpIndexer()`, and `ScoltaSettingsForm::rebuildSubmit()` — were reading `site_name` directly from `scolta.settings` without a fallback. If the admin had never entered a site name in the Scolta settings form, every `ContentItem` was indexed with an empty `siteName`, causing the AI prompt to omit the site name. All four paths now fall back to `\Drupal::config('system.site')->get('name')` (or `$this->configFactory->get('system.site')` where the factory is injected) when the Scolta-specific value is empty or null. ([#43](https://github.com/tag1consulting/scolta-drupal/issues/43))
- **WASM scoring module no longer 404s on subdirectory Drupal installs.** `ScoltaSearchBlock` was constructing the WASM URL with a hardcoded leading `/`, producing paths like `/modules/contrib/scolta/js/wasm/scolta_core.js` regardless of where Drupal is installed. The path is now built with `base_path()`, which returns `/` for root installs and `/drupal/web/` for subdirectory installs. ([#39](https://github.com/tag1consulting/scolta-drupal/issues/39))
- **`composer require tag1/scolta-drupal` now pulls in `drupal/search_api` automatically.** The module declared `search_api:search_api` as a Drupal dependency in `scolta.info.yml` but omitted `drupal/search_api` from `composer.json`, causing `drush en scolta` to fail immediately after a fresh `composer require`. Added `"drupal/search_api": "^1.0"` to the runtime `require` section. ([#41](https://github.com/tag1consulting/scolta-drupal/issues/41))
- **Search result URLs no longer doubled on subdirectory Drupal installs.** `PagefindExporter::buildMetadata()` and `ScoltaContentGatherer::gather()` were calling `->setAbsolute(TRUE)` when generating entity URLs. Pagefind strips the domain from absolute URLs before storing the path, then its JS client resolves that root-relative path against the pagefind base directory — producing doubled paths like `/drupal/web/sites/default/files/scolta-pagefind/drupal/web/node/42` on subdirectory installs. Both methods now call `->toString()` without `setAbsolute()` to produce root-relative URLs that Pagefind stores verbatim. The search JS (`scolta.js`) now also prefers `data.meta?.url` (the verbatim URL from `data-pagefind-meta`) over the Pagefind-resolved `data.url` when rendering result links. ([#40](https://github.com/tag1consulting/scolta-drupal/issues/40))

### Added
- **Search API backend form now exposes indexer mode (auto/PHP/binary).** The `indexer` setting was silently ignored in the Search API server configuration — admins on shared hosting had no way to switch to the PHP indexer from the UI. A select field now appears in the backend config form. When PHP or auto is selected, the Pagefind binary path field is hidden and binary validation is skipped. `triggerRebuild()` also respects the setting: binary mode is only invoked when `indexer: binary` is configured. ([#36](https://github.com/tag1consulting/scolta-drupal/issues/36))

### Fixed
- **`getResolvedBuildDir()` and `getResolvedOutputDir()` no longer fatal on standard Drupal installs where `private://` is unconfigured.** Both methods chained `->realpath()` directly onto `getViaUri()`, which returns `false` when the stream wrapper scheme is not registered. The call is now guarded: if `getViaUri()` returns `false` or `realpath()` fails, the original URI string is returned as the path. ([#35](https://github.com/tag1consulting/scolta-drupal/issues/35))

### Added
- **Amazee.ai auto-configuration: best available Claude model is applied after trial provisioning.** `AmazeeSettingsForm::submitStartTrial()` now applies the auto-selected Sonnet to `scolta.settings ai_model` (if still at the default `claude-sonnet-4-5-20250929`) and Haiku to `ai_expansion_model` (if currently empty). A Drupal Messenger notice reports the selected model name. Model selection delegates to `AmazeeModelResolver` injected into `AmazeeTrialProvisioner`.

### Added
- **Timestamp-based rebuild optimization: skip unchanged entity loads.** `ScoltaContentGatherer::gather()` now accepts an optional `?TimestampManifest $manifest` and `bool $force` parameter. When a manifest is provided and an entity's `changed` timestamp matches the stored value, the gatherer yields a `CachedContentReference` instead of calling `loadMultiple()` — no entity body is loaded, no fields are deserialized. A new `getEntityTimestamps()` method runs a lightweight direct SQL query against the entity's data table (injected `@database` service) to fetch just the `changed` column for a batch of IDs. `ScoltaCommands` now obtains the manifest from `$orchestrator->getTimestampManifest()` and passes it to `gather()`; `--force` passes `null` to bypass the optimization. The `@database` service is now injected into `scolta.content_gatherer` via `scolta.services.yml`.

### Added
- **Amazee.ai integration for Drupal (Phase 2).** Three new classes and updates to `ScoltaAiService` connecting Drupal's config/state layer to the `tag1/scolta-php` Amazee.ai core:
  - `DrupalConfigStorage` implements `ConfigStorageInterface` using Drupal State (`scolta.amazee.credentials`), keeping LiteLLM tokens out of config sync and version control.
  - `BudgetExceededHandler` shows an admin Messenger warning when the Amazee.ai budget is exhausted, rate-limited to once per 24 hours via State.
  - `AmazeeSettingsForm` is a multi-step admin form at `/admin/config/search/scolta/amazee` with two connection paths: (1) free trial — one step: email → provision → connected; (2) upgrade — three steps: email → OTP → region selection → connected.
  - `ScoltaAiService` now accepts `StateInterface` and `BudgetExceededHandler` as constructor arguments. When Amazee credentials are present in State, `buildConfig()` automatically sets `ai_provider: 'openai'`, `ai_api_key`, and `ai_base_url` to the stored LiteLLM endpoint. `getApiKeySource()` returns `'amazee'` when active. `message()`, `conversation()`, and `messageForOperation()` catch "Budget has been exceeded!" errors, convert them to `AmazeeBudgetExceededException`, and delegate to `BudgetExceededHandler`.

### Fixed
- **`drush scolta:build --resume` no longer re-indexes already-processed pages from the beginning.** The generator previously always started at DB offset 0 regardless of resume mode, causing entities 0–N to be written as chunks N–2N and overwriting the correct committed data. On resume, `ScoltaContentGatherer::gather()` now begins at the `pages_processed` offset stored in the build manifest, so each invocation picks up exactly where the previous one left off.

### Added
- **`drush scolta:build` now auto-resumes after a memory abort.** When `IndexBuildOrchestrator` returns `error: 'memory_abort'` (RSS hit the safe threshold mid-build but at least one chunk was committed), `ScoltaCommands` spawns a fresh `drush scolta:build --resume` in the background. The parent process exits, releasing its fragmented heap, and the child starts with clean RSS. This chain repeats until all content is indexed, at which point the final run either completes the merge or spawns `scolta:finalize` as before. Output from each background resume is appended to `/tmp/scolta-resume.log`.

### Fixed
- **`drush scolta:build --resume` no longer re-indexes already-processed pages from the beginning.** The generator previously always started at DB offset 0 regardless of resume mode, causing entities 0–N to be written as chunks N–2N and overwriting the correct committed data. On resume, `ScoltaContentGatherer::gather()` now begins at the `pages_processed` offset stored in the build manifest, so each invocation picks up exactly where the previous one left off.

### Added
- **`drush scolta:finalize` command** merges pre-committed index chunks into the final Pagefind-compatible index in a fresh PHP process. Use this after `drush scolta:build` exits early with "merge deferred" on large corpora where the PHP heap is too fragmented to run the multi-pass pre-merge in-process. The indexing chunks remain on disk between the two commands; `finalize` reads them and produces the live index. `scolta:build` now automatically spawns `scolta:finalize` in a subprocess when it detects a full-heap condition after indexing.

### Fixed
- **`ScoltaContentGatherer::gather()` now loads entities in batches of 10 instead of 100.** `loadMultiple()` allocates all requested entities at once; the previous batch of 100 caused a 25+ MB memory spike per batch (100 entities × ~250 KB each) that PHP's allocator never returns to the OS, producing monotonic heap growth on large corpora like Wikipedia. Reducing to 10 caps the per-batch spike at ~2.5 MB regardless of article size.
- **`ScoltaContentGatherer::gather()` no longer holds an entire entity batch in memory during yielding.** The generator previously iterated with `foreach`, keeping all loaded entity objects alive in the generator's stack frame for the entire batch. The loop now uses `array_shift` so each entity is freed immediately after its ContentItem(s) are yielded. `drupal_static_reset()` and `gc_collect_cycles()` are called after each batch to clear Drupal's per-request static caches (URL aliases, typed data instances, access results, etc.) and PHP's circular reference graph.

### Changed
- **`indexer: auto` now always uses the PHP indexer.** Previously `auto` tried the Pagefind binary first and fell back to PHP. The PHP indexer works on all Drupal hosting environments without `exec()` or Node.js, uses less memory, and supports fast incremental re-indexing. Use `indexer: binary` to keep the old binary-first behaviour.

### Fixed
- **Search result links now point to the correct page URL.** Pagefind's `fullUrl()` prepends its own base path (e.g. `/sites/default/files/scolta-pagefind/`) to every stored root-relative URL before returning `data.url`. The new `resolveUrl()` helper strips that prefix back off, so result links navigate to `/faculty/x` instead of `/sites/default/files/scolta-pagefind/faculty/x`.
- **`drush scolta:build` no longer crashes with "Call to a member function realpath() on false".** `resolvePath()` called `->realpath()` on the return value of `getViaUri()` without checking for `false`. The guard now checks the return value before dereferencing it, and the catch clause is broadened to `\Throwable` to also handle `TypeError`.
- **Decimal scoring values like `0.05` no longer fail "not a valid number" validation.** Scoring fields used `#step => 0.1`, which rejects any value not a multiple of 0.1 (e.g. `0.05`). Changed all scoring decimal fields to `#step => 'any'` to allow arbitrary precision.
- **Settings form no longer blocked by HTML5 validation on collapsed panels.** Number fields inside a closed `<details>` element fail their `min`/`step` constraints but cannot be focused to display the error, silently blocking both Save and Rebuild Index. Added `novalidate` to the form element — Drupal validates all fields server-side, making browser HTML5 validation redundant on admin forms.
- **Site description field now accepts up to 512 characters.** Drupal's default `textfield` maxlength of 128 was too short for multi-sentence site descriptions used in AI prompts. Raised to 512.

### Changed
- **`drush scolta:build --force` now bypasses the per-item token cache** in addition to the existing fingerprint check. Previously `--force` only skipped the `shouldBuild()` fingerprint comparison; the page-word cache (new in this release) was still consulted. With this change, `--force` triggers a full re-tokenization of every content item.

## [0.3.10] - 2026-05-05

### Fixed
- **WASM merge URL lookup now handles normalized URL formats** — multi-key Map with normalized variants prevents result stub fallback; misses logged as `[scolta:merge] WASM URL lookup missed`.
- **Title deduplication threshold lowered to 0.6 Jaccard** — reduces duplicate titles slipping through, with secondary condition for short-title pairs sharing ≥3 words.
- **AI Overview headings now render as HTML** — `#`, `##`, and `###` markdown headings in AI summaries were falling through to `<p>` tags and displaying as raw `#` text. `formatSummary()` now maps them to `<h3>`/`<h4>`/`<h5>` elements.
- **AI summary now describes post-expansion results** — `summarizeResults()` was firing in parallel with the expansion merge, so the AI described the Phase 1 literal-keyword ranking while the displayed results showed the semantically-reordered Phase 2 ranking. Summarization is now deferred until after `mergeExpandedSearchResults()` completes. A `searchVersion` staleness check prevents summarizing results from a superseded search.
- **Relative URLs from pagefind index are absolutized before use** — both the summarize API call and result card `<a>` href attributes now prepend `window.location.origin` when the stored URL starts with `/`, so links work correctly when `ContentItem` stores relative paths.
- **`stripHtml()` now decodes HTML entities** — the previous regex-only implementation left entities like `&#8217;` intact, causing `escapeHtml()` to double-encode them and display literal entity strings in titles and excerpts. `stripHtml()` now uses DOM parsing to both strip tags and decode entities.

## [0.3.9] - 2026-05-02

### Added
- **Site Type selector in admin settings form**: A new "Site Type" section (expanded by default) appears above the Scoring section. Administrators pick the closest preset for their site from a dropdown built dynamically from `ScoltaConfig::getPresets()` — labels and descriptions are never hardcoded. The selected preset is saved to config and applied to scoring values on submit; individual scoring fields override preset values when both are present. The Scoring section description updates to reflect whether a preset is active. Requires scolta-php ≥ 0.3.9 for `getPresets()` / `getPresetValues()`.

## [0.3.8] - 2026-05-01

### Added
- **`ScoltaContentGatherer::gather()` now indexes all translations**: For each entity, the gatherer iterates `getTranslationLanguages()` and yields a separate `ContentItem` per translation. Each item carries the BCP-47 language code so the binary/Pagefind path can emit `<html lang="...">` and filter by language. Single-language entities and English translations keep their original numeric ID for backward compatibility; other language translations get a `-{langcode}` suffix (e.g. `42-es`).

### Fixed
- **Pagefind output path corrected in admin settings form rebuild** — `ScoltaSettingsForm::rebuildWithBinary()` was passing `$outputDir` as `--output-path`, so the binary wrote the index directly into `$outputDir/` instead of `$outputDir/pagefind/`. The reader (`ScoltaSearchBlock`) expects the index at `$outputDir/pagefind/`, so admin-UI rebuilds produced an index that search never found. Fix appends `/pagefind` to match `ScoltaCommands::buildWithBinary`. Also fixes `scolta:status` to check the correct path (`$resolvedDir/pagefind/pagefind.js`).
- **Content gatherer now uses `->processed` for formatted text fields** — previously `->value` returned raw stored text, which could include JSON wrappers and unprocessed tokens. Formatted text fields (`TextItemBase` subclasses: `TextItem`, `TextLongItem`, `TextWithSummaryItem`) now use `->processed` (rendered output after text format filters) with `strip_tags()` for clean text indexing. Plain string fields fall back to `->value`. Fixes comparison article excerpts showing `json {"body":"..."}` wrapper text in search results.

## [0.3.7] - 2026-04-30

### Fixed
- **JS/CSS/WASM assets are now committed directly to the package** instead of being gitignored and generated only during local development. Previously, installing from a Composer VCS repository (GitHub release) left `js/scolta.js`, `css/scolta.css`, and `js/wasm/` missing because the post-install-cmd in scolta-drupal's `composer.json` only runs when installing scolta-drupal in isolation, not when it is a dependency of a Drupal project.

### Improved
- Documentation: clearer use-case descriptions for enterprise Drupal deployments, cross-platform messaging.

## [0.3.6] - 2026-04-29

### Fixed
- **Phrase proximity scoring now works for multi-word queries** — `computeContentWordLocations` replaces Pagefind's `data.locations` (which are not word positions) with real 0-indexed word positions derived from `data.content`. Pages containing adjacent query terms (e.g. "autem comis") now correctly receive the 2.5× `phrase_adjacent_multiplier` boost from scolta-core.

### Added
- **`ai_expansion_model` config key** (default `''`): Optional model for query expansion only. When set, expand-query uses this model while summarize and follow-up continue using `ai_model`. Configurable via the AI section of the admin settings form. Leave blank for unchanged single-model behavior.

## [0.3.5] - 2026-04-28

### Changed
- **Default `expand_primary_weight` lowered to 0.5** (was 0.7) — gives AI-expanded terms more influence for intent-based queries. To restore the previous behavior, set `expand_primary_weight: 0.7` in config.
- **Default `ai_summary_top_n` raised to 10** (was 5) — AI sees more results and curates better for constraint queries and diverse result sets.
- **Default `ai_summary_max_chars` raised to 4000** (was 2000) — supports the increased `ai_summary_top_n` with enough excerpt content for quality curation.

## [0.3.4] - 2026-04-27

### Fixed
- **Hygiene:** Replaced `uniqid('scolta_notice_', TRUE)` with `'scolta_notice_' . bin2hex(random_bytes(8))` in `ScoltaBatchOperations` — avoids period-containing IDs that break downstream sanitizers.
- **Hygiene:** Added `=== false` error check to `file_put_contents` in `PagefindExporter`; throws `RuntimeException` on write failure instead of silently dropping index artifacts.
- **Hygiene:** Added error logging to `file_put_contents` in `ScoltaRebuildWorker` — failed fingerprint writes now appear in logs instead of silently failing.
- **Hygiene:** Added `JSON_THROW_ON_ERROR` to `json_decode` on GitHub API response in `ScoltaCommands` — malformed API responses now log an error instead of silently producing a null release object.
- **Hygiene:** Added source-parse tests preventing reintroduction of `uniqid(..., TRUE)`, unchecked `file_put_contents`, and unguarded `json_decode` on remote responses.

### Added
- **`DrupalCacheDriver` behavior tests.** New `ScoltaCacheBehaviorTest`: verifies the driver contract (get/set/miss/array values) and end-to-end handler+driver caching — second call to `handleExpandQuery`/`handleSummarize` serves from the Drupal cache backend (AI called once), while `cacheTtl=0` calls the AI service both times. Defines a minimal `CacheBackendInterface` stub in-file so tests run without a Drupal install.
- **Config test gap fixes.** Added `custom_stop_words`, `phrase_adjacent_multiplier`, `phrase_near_multiplier`, `phrase_near_window`, and `phrase_window` to `scoringOverrideProvider` in `ScoltaSettingsFormTest`. Fixed array-to-string warning in data provider error messages.
- **AI configuration tests (Phase 5).** Added `testAiLanguagesPropagateToJsScoringConfig`: multi-language `ai_languages` config flows through `ScoltaConfig::$aiLanguages` and appears as `AI_LANGUAGES` in `toJsScoringConfig()` output.
- **Scoring behavior tests (Phase 1).** Added `language` and `recency_strategy` to `scoringOverrideProvider` in `ScoltaSettingsFormTest`, confirming that Drupal's scoring config section correctly propagates these fields through `ScoltaConfig`.

## [0.3.3] - 2026-04-26

### Changed
- **`buildWithPhpIndexer()` budget and intent construction**: Delegated to `MemoryBudgetConfig::fromCliAndConfig()` and `BuildIntentFactory::fromFlags()` (scolta-php), removing duplicated precedence logic.
- **`ScoltaContentGatherer::gather()` batch size**: Increased from 50 to 100 entities per page-load, consistent with the WP and Laravel adapters.
- **`DrushProgressReporter::advance()`**: Now calls `setMessage($detail)` on the Symfony ProgressBar when a detail string is provided, making chunk info visible in verbose Drush output.
- **`ExpandQueryController`, `SummarizeController`, `FollowUpController`**: Now use `AiControllerTrait` (scolta-php) for `AiEndpointHandler` construction, removing the duplicated 7-argument instantiation block from each controller.
- **Anti-pattern CI check.** New `antipatterns` CI job asserts `orchestrator->build()` is always called with a logger argument.
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
