# Changelog

All notable changes to scolta-drupal will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Each Scolta package versions independently; compatibility with scolta-php is expressed by the caret constraint in `composer.json` rather than by matching version numbers.

## [Unreleased]

### Fixed
- `drush scolta:status` and the settings form's `getStatus()` no longer glob() the fragment directory to report a page count: on a six-figure corpus over NFS that directory listing was slow enough that `status` itself became the complaint. Both now read `page_count` from `pagefind-entry.json`, which Pagefind already writes at build time, falling back to the glob only if that file is missing or unreadable.

### Added
- Added `recipes/scolta_umami/`, a recipe that configures Scolta against Drupal's Umami demo profile — presets, facet/sort field mappings, both languages, and the search block — so a demo site can be stood up with `drush recipe`. Development and demo aid, not for production. ([#232](https://github.com/tag1consulting/scolta-drupal/pull/232))
- `recipes/scolta_umami/` now also creates the Search API server (`scolta_pagefind`) and index (`scolta_umami`) that Scolta's status output expects, so a recipe-built demo site tracks content changes instead of reporting "No Scolta index configured".

### Added
- `drush scolta:cleanup` (`scu`): deletes leftover `.scolta-trash-*` directories (scolta-php ≥ 1.5.0 renames the outgoing index to trash and sweeps it after publishing instead of deleting it inline, which on NFS made a finished build look hung for hours). A backstop for builds that died before their own sweep and for the batch-UI path; `--dry-run` lists without deleting, and a stale `.scolta-old` from an interrupted swap is retired and cleaned too. Raises the `tag1/scolta-php` constraint to `^1.5.0`.
- `hook_cron` also sweeps leftover trash, time-boxed by the new `cleanup.cron_seconds` setting (default 180, `0` disables; on web-triggered cron the budget is additionally capped by remaining `max_execution_time`). An interrupted sweep resumes on the next run, and a cron run with no trash logs nothing.

### Changed
- The `functional` CI job now runs inside DDEV (`ddev/github-action-setup-ddev` + `ddev poser` + `ddev symlink-project`), the same environment contributors use locally, against mariadb + nginx instead of a hand-built /tmp Drupal on SQLite and `php -S`. CI and a local run share one invocation: `vendor/bin/phpunit -c phpunit-functional.xml --bootstrap web/core/tests/bootstrap.php`.
- CI now tests the dependency floor: the unit-test matrix is two cells with stable names, `test-lowest` (PHP 8.3, `composer update --prefer-lowest --prefer-stable`) and `test-highest` (PHP 8.4, newest resolution, phpcs). The one blocker was `RebuildNoticeStateTest` using PHPUnit 11's `#[CoversMethod]`; it now uses `#[CoversClass]` so the declared `phpunit ^10.0` floor actually runs.
- Raised the minimum PHP version from 8.1 to 8.3. The 8.1 floor was never exercised by CI, and Drupal 10.3+ runs on 8.3; the CI matrix now brackets the supported range (8.3 lowest, 8.4 highest). Static analysis (phpcs, phpstan) runs only in the highest cell — one canonical analysis environment; the lowest cell is tests-only.
- Rewrote or removed tests that asserted on source code or config-file text instead of behavior, per the sharpened testing rule in `CLAUDE.md`. Rewrites construct the real class with stubbed services and assert on behavior (`ScoltaSearchBlockTest`, `DrupalConfigStorageTest`, `PagefindBuilderTest`, a new `AiApiControllerPipelineTest`, and others); a new `tests/src/Functional/ScoltaDrushCommandsTest.php` (Drush Test Traits) smoke-tests the drush command surface and is the intended home for future command coverage.
- Trimmed low-value and duplicate `tests/src/Functional/` methods to cut suite runtime: each method reinstalls a full Drupal site, so weak assertions (loose status-code ranges), near-identical duplicates across and within files, and cosmetic checks were the most expensive things in the suite to keep. Coverage that was genuinely distinct but cheap to fold in was merged into a sibling test instead of deleted.
- A `CHANGELOG.md` entry is now recommended rather than required, entries are expected to be brief, and the `docs-check` CI job was deleted.
- A test is recommended but no longer required in new PRs. Trivial PRs may skip the test requirement.
- `composer.lock` is no longer committed: this is a library, drupal.org ships the git tarball, and every CI job resolves with `composer update`. The release gate moves from `lock-guard` to `constraint-guard`, which verifies the `tag1/scolta-php` floor is a stable caret constraint satisfied by a published release. ([#234](https://github.com/tag1consulting/scolta-drupal/pull/234))

### Fixed
- `ddev poser` produces a working web root again: `drupal/core-composer-scaffold` was disabled in `allow-plugins`, so `web/` had no `index.php` or `autoload.php`. Scaffold file-mapping exclusions now protect `.editorconfig`/`.gitattributes` instead. ([#238](https://github.com/tag1consulting/scolta-drupal/pull/238))
- The settings form no longer walks the whole Pagefind index directory to report its size on every page load, which took minutes and sometimes timed out on large NFS-backed corpora. ([#235](https://github.com/tag1consulting/scolta-drupal/pull/235))
- Bundles storing prose outside `body`/`field_body`/`field_content` are no longer silently skipped; the searched field list is now the configurable `body_fields` setting, seeded with the three historical names. ([#232](https://github.com/tag1consulting/scolta-drupal/pull/232))

## [1.4.0] - 2026-08-24

### Fixed
- Sites with the locale module enabled render again: the browser bundle was declared by `public://` URI, which locale's JS scanner rejects, returning HTTP 500 on every page carrying the library. A new `scolta_library_info_alter()` resolves deployed URIs to root-relative paths. ([#229](https://github.com/tag1consulting/scolta-drupal/pull/229))
- Resume segments now inherit `--force`, so a forced rebuild of a corpus large enough to segment no longer degrades to incremental for its tail. ([#228](https://github.com/tag1consulting/scolta-drupal/pull/228))

### Changed
- Opened the 1.4.0 line instead of 1.3.1 (the asset-deployment change adds public API) and re-locked `tag1/scolta-php` to the published 1.4.0. ([#230](https://github.com/tag1consulting/scolta-drupal/pull/230))
- The browser bundle now deploys from the installed scolta-php into `public://scolta-assets` at install and on every cache rebuild, instead of being committed here; `composer update` + `drush cr` is enough to ship a new bundle. Removes the `copy-assets` script and the `assets-in-sync` CI job — take it off the required-checks list. ([c4fa6e1](https://github.com/tag1consulting/scolta-drupal/commit/c4fa6e14))

## [1.3.0] - 2026-08-19

### Fixed
- Search API's "Manage fields" form no longer fatals: `scolta_form_search_api_index_fields_alter()` called the nonexistent `IndexInterface::getServer()` and now calls `getServerInstance()`. ([#217](https://github.com/tag1consulting/scolta-drupal/pull/217))
- Injected services on `AmazeeSettingsForm` and `ScoltaSearchBlock` are `protected` and mutable again, since `DependencySerializationTrait` cannot restore `private readonly` properties. ([#217](https://github.com/tag1consulting/scolta-drupal/pull/217))
- `drush scolta:build --force` no longer empties the timestamp manifest, which forced the next build into a second full gather. `--force` now gates only the skip decision, and every loaded entity is still recorded. ([#215](https://github.com/tag1consulting/scolta-drupal/pull/215))
- Bodies dropped by the exporter for being too short are now recorded in the build manifest, so they stop being re-gathered on every warm build. ([#215](https://github.com/tag1consulting/scolta-drupal/pull/215))

### Added
- `--entity-ids` on `drush scolta:build` scopes a build to a comma-delimited list of entity IDs, running them through the same pipeline as a normal build; unloadable IDs are reported and skipped. PHP indexer only. ([#220](https://github.com/tag1consulting/scolta-drupal/pull/220))
- Added a `.ddev` directory for local development, using the [ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib/) add-on. ([#217](https://github.com/tag1consulting/scolta-drupal/pull/217))
- "Facet index loading" setting with three modes — `eager` (existing behavior), `deferred` (download on first filter use), and `disabled` (no facet index or sidebar at all) — for sites whose themes render their own facets or which never filter. ([#213](https://github.com/tag1consulting/scolta-drupal/pull/213))
- `scolta.ai_access`, a decoratable service that answers who may use each AI feature, so per-user rules, quotas, or entitlements have one decision point shared by the endpoints and the block. The shipped implementation just checks `use scolta ai`. ([#214](https://github.com/tag1consulting/scolta-drupal/pull/214))

### Changed
- Raised the `tag1/scolta-php` floor to `^1.3.0` and locked to the released 1.3.0. ([#223](https://github.com/tag1consulting/scolta-drupal/pull/223))
- The AI review workflow now runs on `pull_request_target`, so PRs from forks and Dependabot get a review instead of a guaranteed failure on a withheld secret; the checkout is pinned to the head SHA with credentials not persisted. ([#218](https://github.com/tag1consulting/scolta-drupal/pull/218))
- Set `allow-unsafe-pr-checkout` on the AI review checkout, which `pull_request_target` requires before fork code lands in the workspace; the action treats the tree as data and never executes it. ([#219](https://github.com/tag1consulting/scolta-drupal/pull/219))
- Re-vendored the browser bundle from scolta-php 1.3.0, carrying the `facetMode` implementation and a rebuilt scolta-core 1.0.2 engine. ([#223](https://github.com/tag1consulting/scolta-drupal/pull/223))
- Re-vendored the bundle again: an empty search box now browses the corpus instead of painting nothing, and function words are no longer highlighted in excerpts and titles. ([#216](https://github.com/tag1consulting/scolta-drupal/pull/216))
- Removed the `version-sync` CI job, which imposed lockstep releases with scolta-php rather than checking compatibility — the Composer constraint already states that. Take it off the required-checks list. ([#222](https://github.com/tag1consulting/scolta-drupal/pull/222))

### Fixed
- The search UI no longer advertises AI features to visitors who lack `use scolta ai` — notably anonymous users on a default install, who fired expand and summarize on every query only to get a swallowed 403. ([#214](https://github.com/tag1consulting/scolta-drupal/pull/214))
- Added `AiAccessFunctionalTest` and `AiAccessWiringTest`, covering that the page and the endpoints agree for permitted and unpermitted visitors, and that a config-disabled feature still answers 404 rather than 403. ([#214](https://github.com/tag1consulting/scolta-drupal/pull/214))

## [1.2.0] - 2026-08-07

### Added
- Added `UPGRADE.md` with the 1.2 upgrade notes, chiefly the `ScoltaContentGatherer::gather()` signature change, which fatals at class load for any subclass still declaring the old signature. ([#212](https://github.com/tag1consulting/scolta-drupal/pull/212))

### Changed
- `tag1/scolta-php` is now required as `^1.2.0` with the lock naming the release, and `extra.branch-alias.dev-main` moves to `1.2.x-dev`. ([#212](https://github.com/tag1consulting/scolta-drupal/pull/212))

### Fixed
- Re-vendored the browser bundle from scolta-php 1.2.0: facet counts no longer read high on deduplicated results, and values that no visible result carries are no longer offered. ([#212](https://github.com/tag1consulting/scolta-drupal/pull/212))

### Fixed
- Raised the declared `tag1/scolta-php` floor to `^1.2@dev`, since `^1.1.0` resolved a 1.1.0 that lacks two symbols `DrupalConfigStorage` implements — a fatal on the first request touching the settings form. `ScoltaPhpFloorTest` now guards this, and CI stopped overwriting the constraint before resolving. ([#211](https://github.com/tag1consulting/scolta-drupal/pull/211))
- The committed lock follows the raised floor (`dev-main` from Packagist, with the sibling path repo made non-canonical), and the stable-release requirement moves from CI's `lock-guard` to `release.yml`, where it acts as a real cross-repository release gate. ([#211](https://github.com/tag1consulting/scolta-drupal/pull/211))

### Changed
- A new install now selects no AI provider, and the three surfaces that coalesced an empty value back to `anthropic` no longer do, so an unconfigured site stops presenting itself as an Anthropic site. Existing configuration is untouched. ([#209](https://github.com/tag1consulting/scolta-drupal/pull/209))
- Added a `coherence` CI job (`scripts/check-coherence.mjs`) that compares the development *line* across every place the package states its own version, catching contradictions `version-sync`'s major-number comparison could not. ([#210](https://github.com/tag1consulting/scolta-drupal/pull/210))
- The Amazee.ai connect flow is now two labelled actions: "Try the demo" asks for nothing at all, and "Enter your Amazee credentials" keeps the email → code → region flow. An expired connection can be recovered in place without disconnecting first. ([#209](https://github.com/tag1consulting/scolta-drupal/pull/209))
- The connection source (demo vs. account) is now recorded in State via scolta-php's `ProvenanceAwareConfigStorageInterface`, so the status line states which action established the connection instead of guessing. Requires scolta-php ≥ 1.2.0. ([#212](https://github.com/tag1consulting/scolta-drupal/pull/212))

### Fixed
- Updated three functional tests and removed a dead `instanceof` guard for the no-default-provider rule. ([#209](https://github.com/tag1consulting/scolta-drupal/pull/209))

### Added
- Added `ManualProviderAndTwoActionConnectTest`, which pins the no-default-provider and two-action-connect policy structurally. ([#209](https://github.com/tag1consulting/scolta-drupal/pull/209))

### Changed
- Amazee.ai is now reported as a single API key source, following scolta-php#273, which collapsed `amazee:operator` and `amazee:auto`; the unsupported "auto-provisioned free trial" wording is gone. ([#208](https://github.com/tag1consulting/scolta-drupal/pull/208))

### Fixed
- `drush scolta:build` no longer exits 0 while a detached background chain finishes the index. Resume segments run in the foreground with streamed output, every failure path throws, and success is reported only after the index verifies. ([#208](https://github.com/tag1consulting/scolta-drupal/pull/208))
- The resume offset no longer confuses pages with entities, which made a resumed segment either re-index a translated corpus from scratch or renumber real pages on a monolingual one; the boundary is now an entity ID. ([#208](https://github.com/tag1consulting/scolta-drupal/pull/208))
- The memory budget profile labels no longer read as a guarantee about total process memory — the figure budgets Scolta's own work, on top of Drupal's baseline. ([#208](https://github.com/tag1consulting/scolta-drupal/pull/208))

### Added
- An ordinary content edit now updates the index incrementally instead of rebuilding the whole corpus: the queue item carries the changed entity and its per-translation item IDs, and the worker stages upserts and deletes against the page-table ledger. Falls back to a full build (with a logged reason) for untargeted requests, oversized change sets, or a missing ledger, and is inert until a scolta-php with `IncrementalIndexUpdater` is installed. ([#206](https://github.com/tag1consulting/scolta-drupal/pull/206))
- Added `hook_entity_delete()`, so deleting a node removes its page instead of leaving it searchable — and clickable through to a 404 — until an unrelated rebuild. ([#206](https://github.com/tag1consulting/scolta-drupal/pull/206))
- Removing a translation now removes its page: the queue payload unions the pre-save item IDs so the orphan is tombstoned. ([#206](https://github.com/tag1consulting/scolta-drupal/pull/206))
- `gatherByIds()` accepts a timestamp manifest, so the admin "Index Now" PHP path can skip unchanged entities instead of re-rendering its whole slice. ([#206](https://github.com/tag1consulting/scolta-drupal/pull/206))

### Changed
- The corpus walk now fetches IDs in keyset-paged batches of 200 while still loading entities 10 at a time; entity-query overhead is per-call, not per-row, so this cut 12,397 queries to a fraction without changing the memory profile. ([#206](https://github.com/tag1consulting/scolta-drupal/pull/206))
- A gather batch that loaded nothing skips `resetCache()`, `drupal_static_reset()` and `gc_collect_cycles()`; the last cost 134ms per call against the in-memory manifest and ledger, 68% of a warm gather. Measured 2.33x faster on an identical 20,000-item prefix. ([#206](https://github.com/tag1consulting/scolta-drupal/pull/206))
- The build lock is renewed at every chunk boundary rather than taking a fixed 3600s lease, so a long build never loses it and a crashed one frees it in minutes. ([#206](https://github.com/tag1consulting/scolta-drupal/pull/206))

### Fixed
- The queue worker no longer deletes rebuild requests enqueued during a build, which silently dropped those edits. It now claims the queue up front and deletes only the handles it actually covered. ([#206](https://github.com/tag1consulting/scolta-drupal/pull/206))
- The queue worker's build-state fallback (`private://scolta-build`) disagreed with the shipped default and the Drush command (`public://scolta-build`), so on a site with no saved config neither path could ever be incremental. ([#206](https://github.com/tag1consulting/scolta-drupal/pull/206))

### Fixed
- Re-vendored the browser bundle: the AI summary's "Show more" control now follows viewport width via a `ResizeObserver`, and a summarize failure can no longer strand the loading skeleton. ([#205](https://github.com/tag1consulting/scolta-drupal/pull/205))

### Fixed
- Re-vendored the browser bundle: the AI summary reserves a fixed-height slot in the frame the results paint in, so it no longer shoves the result list down (CLS 0.437 → 0.000). Collapsed height is themeable via `--scolta-summary-collapsed-lines`. ([#204](https://github.com/tag1consulting/scolta-drupal/pull/204))

### Added
- Re-vendored the browser bundle: search-as-you-type suggestions now carry their fragment `meta` map, and `Scolta.setSuggestionRenderer(fn)` lets a theme render the inside of each suggestion row. Both opt-in and additive; no index rebuild. ([#203](https://github.com/tag1consulting/scolta-drupal/pull/203))
- Added `.github/upstream-preview` to name the scolta-php branch a re-vendor comes from, so the informational preview job tests against the unmerged upstream. ([#203](https://github.com/tag1consulting/scolta-drupal/pull/203))

## [1.1.0] - 2026-08-01

### Changed
- Raised `tag1/scolta-php` to `^1.1.0` and pointed `extra.branch-alias.dev-main` at `1.1.x-dev`; the module imports four symbols and reads four `ScoltaConfig` properties that 1.0.5 does not have. ([#201](https://github.com/tag1consulting/scolta-drupal/pull/201))
- The managed Amazee.ai gateway is now enabled explicitly — select the provider, then complete the connect flow — and the stored connection is used only while `amazee` is the selected provider, so it can no longer shadow an operator's own key. Switching providers clears it. ([#197](https://github.com/tag1consulting/scolta-drupal/pull/197))
- `use scolta ai` is no longer granted to the anonymous role at install, since the three AI endpoints make cost-bearing LLM calls; the authenticated grant stays. ([#197](https://github.com/tag1consulting/scolta-drupal/pull/197))
- `scolta_update_10004()` carries existing sites onto both rules: a site whose stored connection was already serving AI traffic gets `amazee` recorded as its provider, and the anonymous permission is revoked. Config-managed sites must re-export. ([#197](https://github.com/tag1consulting/scolta-drupal/pull/197))
- Added behavioral coverage for the opt-in gateway across install, provider switching, key-source reporting, and the update hook. ([#197](https://github.com/tag1consulting/scolta-drupal/pull/197))

### Fixed
- A site connected to the managed gateway could not authenticate: `resolveApiKey()` read the raw State array instead of `DrupalConfigStorage::load()`, so the encrypted token was sent as-is. ([#198](https://github.com/tag1consulting/scolta-drupal/pull/198))
- The key-source matrix test now writes `SCOLTA_API_KEY` into the test site's `settings.php` as well, since the built-in server does not inherit `putenv()` from the PHPUnit process. ([#197](https://github.com/tag1consulting/scolta-drupal/pull/197))
- `getApiKeySource()` reported Amazee.ai while an explicit key actually served every request, because reporting and resolution had opposite precedence; both now derive from one resolution ([#188](https://github.com/tag1consulting/scolta-drupal/issues/188)). ([#196](https://github.com/tag1consulting/scolta-drupal/pull/196))
- Amazee gateway model aliases are no longer written into the operator-facing `ai_model`, which left sites naming a model Anthropic does not recognize once the trial ended. New `amazee_model` / `amazee_expansion_model` keys hold them ([#187](https://github.com/tag1consulting/scolta-drupal/issues/187)). ([#194](https://github.com/tag1consulting/scolta-drupal/pull/194))
- The Amazee.ai trial form wrote the same aliases into the shared keys and now writes the gateway keys instead. ([#194](https://github.com/tag1consulting/scolta-drupal/pull/194))
- `modelIsResolved()`, the `hasResolvedModels:` predicate and the degraded-client guard now read the gateway key rather than `ai_model`. ([#194](https://github.com/tag1consulting/scolta-drupal/pull/194))
- `scolta_update_10003()` backfills the gateway keys and, on sites with stored Amazee credentials, moves a non-default `ai_model` into `amazee_model`; sites without credentials are left alone. ([#194](https://github.com/tag1consulting/scolta-drupal/pull/194))
- Added functional coverage for the model-key separation and the migration hook, all failing against the pre-fix code. ([#194](https://github.com/tag1consulting/scolta-drupal/pull/194))

### Added
- Search as you type: typing now opens a suggestions dropdown, with ten settings on the admin form. Typing still never runs a full search, and no index rebuild is needed. ([#184](https://github.com/tag1consulting/scolta-drupal/pull/184))
- `ScoltaSearchBlock::build()` bridges the ten SAYT keys into `drupalSettings.scolta`, reading them from Drupal config so the feature works against any scolta-php in the supported range. ([#184](https://github.com/tag1consulting/scolta-drupal/pull/184))
- `scolta_update_10002()` writes the SAYT defaults onto existing sites, adding only keys that are absent so a deliberate `sayt_enabled: false` survives. ([#184](https://github.com/tag1consulting/scolta-drupal/pull/184))
- Added coverage for the whole SAYT path, leaning on non-default values since every failure mode here looks like a working feature on a default site. ([#184](https://github.com/tag1consulting/scolta-drupal/pull/184))
- Re-vendored the browser bundle: four render lifecycle events plus `Scolta.setResultRenderer(fn)`, so a Drupal theme can supply its own result markup instead of Scolta's card. ([#180](https://github.com/tag1consulting/scolta-drupal/pull/180))
- The six specificity and co-occurrence ranking settings are now configurable from Drupal, with defaults byte-equal to the JS fallbacks so existing rankings do not move. ([#176](https://github.com/tag1consulting/scolta-drupal/pull/176))
- Added `BrowserConfigParityFunctionalTest`, which diffs the emitted `drupalSettings.scolta` key set against the keys the committed bundle reads, in both directions, so a browser-side key can no longer be settable from nowhere. ([#176](https://github.com/tag1consulting/scolta-drupal/pull/176))
- Added regression coverage for the `hide_empty_facets` bridge, including the load-bearing `false` direction. ([#176](https://github.com/tag1consulting/scolta-drupal/pull/176))
- Added the `hide_empty_facets` setting (default on), which hides zero-count facet values for the current query while keeping active values visible. ([#175](https://github.com/tag1consulting/scolta-drupal/pull/175))

### Changed
- Re-vendored the bundle: the facet panel now counts the AI expansion as well as the typed query, so counts stop reading low and expansion-only values stop being hidden. ([#185](https://github.com/tag1consulting/scolta-drupal/pull/185))
- `composer copy-assets` is no longer run as a `post-install-cmd` / `post-update-cmd` side effect; re-vendoring is now a deliberate command a human runs. ([#181](https://github.com/tag1consulting/scolta-drupal/pull/181))
- Replaced the ~87-line asset manifest comparison with a byte comparison in a new `assets-in-sync` job, which needs no manifest and works against every scolta-php version. ([#181](https://github.com/tag1consulting/scolta-drupal/pull/181))
- Added an informational `upstream-preview` CI job: commit a git ref into `.github/upstream-preview` and the suite runs against that unmerged scolta-php. Never a merge gate, and never muted. ([#186](https://github.com/tag1consulting/scolta-drupal/pull/186))
- Re-vendored the bundle: Scolta reads its own facet index instead of Pagefind's filter chunks, removing a per-search cost that scaled with the corpus. **Requires a full index rebuild** — run `drush scolta:index` after updating; until then facets fall back to the old path. ([#179](https://github.com/tag1consulting/scolta-drupal/pull/179))
- Re-vendored the bundle: every query stopped running its Pagefind search twice, and the result list no longer waits on facet counts. ([#178](https://github.com/tag1consulting/scolta-drupal/pull/178))
- Re-vendored the bundle: co-occurrence ranking, so a document agreeing with several query and expansion terms outranks one matching a single strong term, without loading full documents for non-seeding terms. ([#173](https://github.com/tag1consulting/scolta-drupal/pull/173))
- Re-vendored the bundle: partial matches are ranked by term specificity, so a common word no longer floods the head of the list. Gated by `specificityWeighting` (default on). ([#172](https://github.com/tag1consulting/scolta-drupal/pull/172))
- Re-vendored the bundle: Pagefind index chunks are preloaded while the user types, behind a debounce and a 2-character floor. ([#176](https://github.com/tag1consulting/scolta-drupal/pull/176))
- Opened the 1.0.6-dev development cycle. ([#176](https://github.com/tag1consulting/scolta-drupal/pull/176))

### Fixed
- The manifest staleness guard had only ever been source-inspected; `ScoltaContentGathererCacheTest` now drives the real `gather()` against a real `TimestampManifest`. ([#192](https://github.com/tag1consulting/scolta-drupal/pull/192))
- The timestamp manifest omitted `metadata`, so an unchanged entity was replayed into the index with an empty metadata array. ([#191](https://github.com/tag1consulting/scolta-drupal/pull/191))
- `ScoltaAiService::getApiKey()` no longer throws a `TypeError` when `settings.php` stores a boolean `FALSE` — the value `getenv()` returns for an undefined variable — which killed every Drush command in the environment. ([#186](https://github.com/tag1consulting/scolta-drupal/pull/186))
- Added `scolta_update_10001()` to backfill the `use scolta ai` grant, which previously reached fresh installs only, leaving existing sites with a 403 from every AI endpoint ([#110](https://github.com/tag1consulting/scolta-drupal/issues/110)). ([#183](https://github.com/tag1consulting/scolta-drupal/pull/183))
- Removed the hardcoded `version` from `composer.json`, which made the drupal.org facade announce `1.0.6-dev` on every branch and broke `composer install` on sites tracking `dev-1.0.x`. ([#181](https://github.com/tag1consulting/scolta-drupal/pull/181))
- The asset parity check had never been able to fail: Composer's post-update hook rewrote the committed assets before the verification step compared them. ([#181](https://github.com/tag1consulting/scolta-drupal/pull/181))
- Re-vendored `js/scolta.js` and the bundled WASM, picking up the identifier/proper-noun search fix and the grounding rules that stop the summarizer claiming the collection lacks content it cannot see. ([#171](https://github.com/tag1consulting/scolta-drupal/pull/171))
- Fixed the Drupal AI module provider path, which failed every AI feature with "Summary unavailable": `getDefaultProviderForOperationType()` returns an array, not a plugin ID ([#163](https://github.com/tag1consulting/scolta-drupal/issues/163)). ([#164](https://github.com/tag1consulting/scolta-drupal/pull/164))

### Known limitations
- Facet counts do not exactly match the filtered result list once AI query expansion has run ([scolta-php#265](https://github.com/tag1consulting/scolta-php/issues/265)); unexpanded queries are exact. Targeted for 1.1.1. ([#201](https://github.com/tag1consulting/scolta-drupal/pull/201))

## [1.0.5] - 2026-06-27

### Fixed
- The functional CI job sets `audit.block-insecure false` on its throwaway Drupal site, so a newly published core advisory no longer stops the pinned core from resolving and fails every PR. ([#156](https://github.com/tag1consulting/scolta-drupal/pull/156))
- The asset-drift guard's manifest-absent fallback now verifies all four canonical assets rather than `scolta.js` alone, which let a stale WASM scorer pass CI. ([#156](https://github.com/tag1consulting/scolta-drupal/pull/156))

### Added
- Added a `dist-archive` CI job and `scripts/validate-dist-archive.sh`, which reproduces the drupal.org tarball and asserts that no export-ignored path leaked in and no runtime asset was dropped. ([#153](https://github.com/tag1consulting/scolta-drupal/pull/153))

### Fixed
- Amazee.ai configuration self-heals when stored credentials lack resolved model names, recovering on a later request instead of leaving AI unavailable. ([#160](https://github.com/tag1consulting/scolta-drupal/pull/160))
- Expired or revoked Amazee.ai credentials now recover automatically (one attempt per window, managed path only) and `/health` reports `ai_usable: false` instead of staying green. ([#160](https://github.com/tag1consulting/scolta-drupal/pull/160))
- Re-synced `js/scolta.js` and `css/scolta.css` from scolta-php, fixing an AI Overview stall of 56–138s caused by the sub-word frequency guard downloading the entire word index. ([#151](https://github.com/tag1consulting/scolta-drupal/pull/151))

### Changed
- Consolidated fragment-file enumeration into `IndexLocator::fragmentFiles()`, removing the duplicate glob in the health controller. ([#155](https://github.com/tag1consulting/scolta-drupal/pull/155))
- `GET /api/scolta/v1/health` now answers status-only to anyone (so uptime monitors work) and keeps the full diagnostic payload behind `administer scolta`, which it previously exposed to anonymous traffic. ([#150](https://github.com/tag1consulting/scolta-drupal/pull/150))

### Security
- Added flood control on the three anonymous AI endpoints — per-IP and site-wide thresholds checked before any AI work, failing closed with HTTP 429, configurable under a new "Rate Limiting" settings section. ([#149](https://github.com/tag1consulting/scolta-drupal/pull/149))
- Added CSRF protection to the dismiss-notice route and closed an open redirect via protocol-relative `?destination=//evil.com`. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- Admin "Rebuild Index" routes the binary build through `PagefindBuilder::build()` instead of hand-rolling an `exec()` command string. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))

### Fixed
- Removed the cron rebuild worker's fingerprint-write path, whose error branch called a `getLogger()` that `QueueWorkerBase` does not have — a baselined fatal. The worker now injects its dependencies and streams through `IndexBuildOrchestrator`. ([#155](https://github.com/tag1consulting/scolta-drupal/pull/155))
- `hook_requirements()` read `pagefind_binary` instead of `pagefind.binary`, so a configured binary path was ignored in the status report. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- `HealthController` and `drush scolta:status` reported "Drupal AI module" based on module presence alone, rather than on the configured provider. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- `drush scolta:clear-cache` wiped the entire shared `cache.default` bin; it now bumps `scolta.generation` and deletes only Scolta's own keys. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- Search-API-triggered binary rebuilds served stale AI caches for up to 30 days, invalidating a tag nothing set; the success path now bumps the generation. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- Config schema used unsupported `mapping: {'*': …}` wildcards for four arbitrary-key maps, now `type: sequence`; added the missing `auto_rebuild_delay` key. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- The settings form silently discarded malformed recency-curve JSON and pipe-less key/value lines; both now produce validation errors naming the offending line. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- `settings.php` `$config['scolta.settings']` overrides now apply to AI traffic — `buildConfig()` read `getRawData()`, which bypasses overrides. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- Fixed the search block's missing `user.permissions` and `languages:language_content` cache contexts and `config:system.site` tag, and made the admin notice and attribution translatable with routed rather than hardcoded paths. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- Synced `js/scolta.js` from scolta-php with four browser-render fixes, including a zero-result panel that stayed blank for the whole AI expansion round-trip. ([#147](https://github.com/tag1consulting/scolta-drupal/pull/147))

### Changed
- Unified the four divergent content pipelines through `ScoltaContentGatherer`; the admin rebuild button, the Batch API step and the cron worker had each been building a different index than `drush scolta:build`. ([#149](https://github.com/tag1consulting/scolta-drupal/pull/149))
- The cron queue worker runs the same streamed, debounced pipeline as `drush scolta:build` instead of eager-loading every published node. ([#149](https://github.com/tag1consulting/scolta-drupal/pull/149))
- Added a shared `scolta.index_locator` service, so the four places that checked whether an index exists stop disagreeing about it. ([#149](https://github.com/tag1consulting/scolta-drupal/pull/149))
- Extracted the ~95% identical AI controllers into `AiApiControllerBase`, which owns the flood check, body parsing, and response mapping. ([#160](https://github.com/tag1consulting/scolta-drupal/pull/160))
- The admin UI now surfaces a no-longer-accepted Amazee.ai connection with a call to action, and `/health` reports it as degraded rather than swallowing it. ([#160](https://github.com/tag1consulting/scolta-drupal/pull/160))
- Dependency injection sweep replacing `\Drupal::` statics across seven classes. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- Replaced a copied Amazee budget-error magic string with `BudgetAwareProviderDecorator::isBudgetError()`, which also walks the exception chain. Requires scolta-php ≥ 1.0.5. ([#160](https://github.com/tag1consulting/scolta-drupal/pull/160))
- `phpcs` now lints `scolta.module` and `scolta.install`, and CI no longer suppresses warnings. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))
- Extracted the default AI model literal into `ScoltaSettingsForm::DEFAULT_AI_MODEL`. ([#160](https://github.com/tag1consulting/scolta-drupal/pull/160))

### Internal
- Removed dead code (`ScoltaBatchOperations::processChunk()`, two unused `MemoryBudgetSettingsFieldSet` methods, an unread constructor property) and regenerated the PHPStan baseline. ([#148](https://github.com/tag1consulting/scolta-drupal/pull/148))

## [1.0.3] - 2026-06-05

### Added
- Added the `expansion_combine_mode` scoring setting (`relevance_union` default, `round_robin`), which deals the summarizer top candidates from each expansion sub-query so it sees breadth across sub-topics. ([#142](https://github.com/tag1consulting/scolta-drupal/pull/142))
- Added a `hook_help()` pointer to the new README "Tuning search breadth" section from the help page and settings form. ([#139](https://github.com/tag1consulting/scolta-drupal/pull/139))

### Changed
- Synced `js/scolta.js`: the facet panel is now index-driven and stable in alphabetical order, with counts that are the exact breakdown of the typed query. ([#144](https://github.com/tag1consulting/scolta-drupal/pull/144))
- `expansion_combine_mode` is now preset-defaulted (`round_robin` for the catalog, editorial and e-commerce presets) and the `expansion_per_term_top_k` setting is removed. ([#142](https://github.com/tag1consulting/scolta-drupal/pull/142))
- Reworded the `expand_subword_max_frequency` field to "Search breadth (advanced)" and added a README tuning section; no scoring logic or defaults changed. ([#139](https://github.com/tag1consulting/scolta-drupal/pull/139))
- CI now verifies all four duplicated front-end assets against scolta-php's `ASSETS.sha256` manifest rather than `js/scolta.js` alone. ([#138](https://github.com/tag1consulting/scolta-drupal/pull/138))
- Dropped the three `ScoltaAiService` overrides that existed only to wrap `parent::` in a budget-exception catch, now that the scolta-php base class does it. Requires scolta-php ≥ 1.0.3. ([#137](https://github.com/tag1consulting/scolta-drupal/pull/137))
- Resynced the bundled scolta-core WASM, carrying the `summarize` prompt's explicit output-length budget that prevents AI overviews being truncated mid-sentence. ([#136](https://github.com/tag1consulting/scolta-drupal/pull/136))
- Opened the 1.0.3-dev development cycle. ([#135](https://github.com/tag1consulting/scolta-drupal/pull/135))

### Fixed
- Synced `js/scolta.js`: applying a facet filter no longer collapses that facet to its single selected value, leaving the user unable to switch or broaden it. ([#143](https://github.com/tag1consulting/scolta-drupal/pull/143))

### Internal
- The GitHub release workflow is now notes-only; the vendor-bundled release zip and its `validate-zip` job had no consumer, since drupal.org builds the canonical tarball. ([#140](https://github.com/tag1consulting/scolta-drupal/pull/140))

## [1.0.2] - 2026-06-02

### Fixed
- The AI Provider settings field shows the saved provider instead of always showing Amazee when Amazee credentials are present; auto-detection now applies only when no provider was saved. Display-only — persisted values and live API calls were already correct. ([#128](https://github.com/tag1consulting/scolta-drupal/pull/128))

### Added
- Added the `expand_subword_deny_list` scoring setting: listed words are never auto-exempted from the sub-word frequency guard even when typed, but stay searchable and scorable. ([#130](https://github.com/tag1consulting/scolta-drupal/pull/130))
- Added the `expand_subword_max_frequency` scoring setting (default `0.05`), which controls the sub-word frequency guard that restores broad-query recall without reintroducing high-frequency noise. ([#129](https://github.com/tag1consulting/scolta-drupal/pull/129))

### Changed
- Re-synced `js/scolta.js` and the bundled WASM after scolta-php and scolta-core reverted the query-word-importance line, which validation showed changed result ordering on zero real queries. ([#133](https://github.com/tag1consulting/scolta-drupal/pull/133))
- Opened the 1.0.2-dev development cycle. ([#127](https://github.com/tag1consulting/scolta-drupal/pull/127))
- Scoring default tuning to match scolta-php: `cross_list_bonus` 0.15 → 0.05, `recency_boost_max` 0.5 → 0.25, `title_match_boost` 1.0 → 2.0. ([#129](https://github.com/tag1consulting/scolta-drupal/pull/129))
- Updated the bundled `tag1/scolta-php` pin to the 1.0.2 Packagist release. ([#134](https://github.com/tag1consulting/scolta-drupal/pull/134))
- Release archive cleanup now strips all vendored `*.neon`/`*.dist`/`*.log` dev-config files, which had been tripping `validate-zip` and leaving the v1.0.1 release CI red. ([#134](https://github.com/tag1consulting/scolta-drupal/pull/134))
- Synced `js/scolta.js` with the frequency-guarded sub-word expansion, the typed-word exemption, and the semantic query-word importance gate. ([#131](https://github.com/tag1consulting/scolta-drupal/pull/131))

## [1.0.1] - 2026-05-30

### Changed
- `PagefindExporter` writes a nested directory layout mirroring canonical URLs instead of flat filenames, aligning binary indexer output with the PHP indexer. ([#126](https://github.com/tag1consulting/scolta-drupal/pull/126))
- HTML file counting walks the directory recursively via `ContentExporter::countHtmlFiles()` rather than a flat glob. ([#126](https://github.com/tag1consulting/scolta-drupal/pull/126))
- AI summary citation URLs prefer the canonical `meta.url` over the Pagefind file path. ([#126](https://github.com/tag1consulting/scolta-drupal/pull/126))
- Decoupled the release build from lockstep scolta-php tagging; the committed lock pins a stable Packagist release, guarded by a new `lock-guard` CI job. ([#124](https://github.com/tag1consulting/scolta-drupal/pull/124))
- Normalized the `tag1/scolta-php` constraint to `^1.0` and `minimum-stability` to `stable`. ([#124](https://github.com/tag1consulting/scolta-drupal/pull/124))
- The release archive is built from a fail-closed allowlist rather than a denylist of exclude patterns. ([#124](https://github.com/tag1consulting/scolta-drupal/pull/124))
- Added a `version-consistency` CI job verifying `composer.json` and `scolta.info.yml` agree. ([#124](https://github.com/tag1consulting/scolta-drupal/pull/124))

## [1.0.0] - 2026-05-27

### Added
- Pass `filterFieldDescriptions` to the frontend via `drupalSettings`, enabling subcategory matching so "physics" can match the "Science" filter. ([#115](https://github.com/tag1consulting/scolta-drupal/pull/115))
- Added `FilterPipelineConsistencyTest`, validating that the filter and sort config sections stay in sync. ([#121](https://github.com/tag1consulting/scolta-drupal/pull/121))
- Synced `scolta.js`: new `exact_title_match_boost` (default 5.0x) ranks an exact title match first regardless of BM25 differentials. ([#121](https://github.com/tag1consulting/scolta-drupal/pull/121))
- Config-driven field-to-dimension auto-mapping (`field_mappings`), removing the need for a custom module in most cases. ([#121](https://github.com/tag1consulting/scolta-drupal/pull/121))
- Added `scolta.api.php` hook documentation. ([#121](https://github.com/tag1consulting/scolta-drupal/pull/121))

### Fixed
- Synced `scolta.js`: two-pass filter matching prevents substring overlap ("Apollo 1" over "Apollo 11"), and `filter_hint` values are canonicalized against cached Pagefind filters. ([#120](https://github.com/tag1consulting/scolta-drupal/pull/120))
- `drush scolta:build --force` no longer crashes when `file_private_path` is unconfigured: the build directory defaults to `public://scolta-build`, with a fallback in every resolution path. ([#119](https://github.com/tag1consulting/scolta-drupal/pull/119))
- The AI Overview renders `*italic*` and `***bold italic***` instead of literal asterisks. ([#118](https://github.com/tag1consulting/scolta-drupal/pull/118))
- Synced `scolta.js`: a sorted search returning fewer than 20 results re-runs unsorted and sorts client-side, so an index lacking sort data no longer produces near-empty results. ([#117](https://github.com/tag1consulting/scolta-drupal/pull/117))
- Synced `scolta.js`: subject filter matches now update the sidebar checkboxes and filter badges. ([#116](https://github.com/tag1consulting/scolta-drupal/pull/116))
- Synced `scolta.js`: sort-only queries with unmatched subject terms fall through to relevance ranking, and subcategory terms match parent filter values. ([#115](https://github.com/tag1consulting/scolta-drupal/pull/115))
- Synced `scolta.js`: `computeFilterCounts()` iterates all values in multi-value filter arrays instead of counting only the first. ([#114](https://github.com/tag1consulting/scolta-drupal/pull/114))
- Synced `scolta.js`: cross-list results get an additive bonus instead of max-score deduplication, and multi-word expansion terms are no longer word-exploded. ([#112](https://github.com/tag1consulting/scolta-drupal/pull/112))
- `ScoltaContentGatherer::gather()` uses the generic entity ID key instead of hardcoding `nid`, enabling non-node entity types. ([#111](https://github.com/tag1consulting/scolta-drupal/pull/111))
- Synced `scolta.js`: facet counts refresh after filter selection, and selecting two or more values in one dimension produces OR results instead of zero. ([#109](https://github.com/tag1consulting/scolta-drupal/pull/109))

### Changed
- Synced `scolta.js`: the sort path discovers Pagefind filters at init and matches subject terms against filter values, replacing the fragile subject-intersection heuristic. ([#108](https://github.com/tag1consulting/scolta-drupal/pull/108))

## [1.0.0-rc4] - 2026-05-18

### Fixed
- Excluded vendor `test/`/`tests/` directories and dev-only config from the release ZIP (`wamania/php-stemmer/test/files/` alone is ~17 MB), with `validate-zip` failing on regressions ([#105](https://github.com/tag1consulting/scolta-drupal/issues/105)). ([#106](https://github.com/tag1consulting/scolta-drupal/pull/106))
- Added an "External Services" section to `README.md` documenting every external HTTP connection with terms and privacy links. ([#104](https://github.com/tag1consulting/scolta-drupal/pull/104))

### Added
- Added the `show_attribution` setting (default off), an optional "Powered by Scolta" line on the search page. ([#102](https://github.com/tag1consulting/scolta-drupal/pull/102))
- The health endpoint returns `index` detail — `built`, `fragments`, `last_build`, `integrity` — matching the Laravel response shape ([#76](https://github.com/tag1consulting/scolta-drupal/issues/76)). ([#100](https://github.com/tag1consulting/scolta-drupal/pull/100))

### Fixed
- Synced `scolta.js`: a foreign-language search no longer flashes "No Results Found" before expansion results arrive. ([#103](https://github.com/tag1consulting/scolta-drupal/pull/103))
- `buildConfig()` respects top-level keys set via `drush config:set`, which were previously overwritten by the nested `display.*` flattening ([#75](https://github.com/tag1consulting/scolta-drupal/issues/75)). ([#101](https://github.com/tag1consulting/scolta-drupal/pull/101))
- The settings form validates the API Base URL field, rejecting non-URLs and schemeless values on save ([#86](https://github.com/tag1consulting/scolta-drupal/issues/86)). ([#99](https://github.com/tag1consulting/scolta-drupal/pull/99))
- Saving scoring configuration immediately invalidates the page cache: the search block now declares the `config:scolta.settings` cache tag ([#85](https://github.com/tag1consulting/scolta-drupal/issues/85)). ([#98](https://github.com/tag1consulting/scolta-drupal/pull/98))
- AI endpoints no longer return 403 for anonymous users; `hook_install()` grants `use scolta ai` to the anonymous and authenticated roles ([#84](https://github.com/tag1consulting/scolta-drupal/issues/84)). ([#97](https://github.com/tag1consulting/scolta-drupal/pull/97))

### Added
- Synced `scolta.js`: the AI Overview context now includes structured metadata and sort/filter indicators. ([#95](https://github.com/tag1consulting/scolta-drupal/pull/95))

### Fixed
- Amazee.ai AI requests no longer route through the Drupal AI module when both are present; Amazee.ai always uses its own managed gateway ([#89](https://github.com/tag1consulting/scolta-drupal/issues/89)). ([#92](https://github.com/tag1consulting/scolta-drupal/pull/92))

### Added
- Added a "Drupal AI Integration" section to `README.md` covering the three provider paths and the upgrade path between them ([#91](https://github.com/tag1consulting/scolta-drupal/issues/91)). ([#94](https://github.com/tag1consulting/scolta-drupal/pull/94))
- Added `drupal/ai` to the `composer.json` `suggest` field ([#91](https://github.com/tag1consulting/scolta-drupal/issues/91)). ([#94](https://github.com/tag1consulting/scolta-drupal/pull/94))
- Added a "Drupal AI module" provider option, routing AI requests through that module's plugin manager and its 48+ providers. Appears only when `drupal/ai` is installed ([#90](https://github.com/tag1consulting/scolta-drupal/issues/90)). ([#93](https://github.com/tag1consulting/scolta-drupal/pull/93))
- The model, API key, expansion model and base URL fields are hidden when "Drupal AI module" is selected, since that module manages them ([#90](https://github.com/tag1consulting/scolta-drupal/issues/90)). ([#93](https://github.com/tag1consulting/scolta-drupal/pull/93))

### Changed
- Drupal AI module integration is now opt-in; merely installing `drupal/ai` used to silently reroute all AI requests and break explicitly configured providers ([#89](https://github.com/tag1consulting/scolta-drupal/issues/89)). ([#92](https://github.com/tag1consulting/scolta-drupal/pull/92))
- Drupal AI integration uses `getDefaultProviderForOperationType('chat')` rather than `createInstance($config->aiProvider)`, which could only fail since `drupal_ai` is not a provider plugin ID ([#90](https://github.com/tag1consulting/scolta-drupal/issues/90)). ([#93](https://github.com/tag1consulting/scolta-drupal/pull/93))

### Added
- Added the `hook_scolta_content_item_alter()` extension point, letting modules populate `ContentItem::$sortable` and `$metadata` from entity fields; the manifest stores `sortable` so incremental builds preserve them. ([#88](https://github.com/tag1consulting/scolta-drupal/pull/88))
- Synced `js/scolta.js` with scolta-php#108, adding the dismissable filter intent badges. ([#87](https://github.com/tag1consulting/scolta-drupal/pull/87))
- Added sortable field descriptions, filter fields, and filter field descriptions to the Content section of the settings form, with matching schema. ([#83](https://github.com/tag1consulting/scolta-drupal/pull/83))

### Fixed
- Added the supported-but-undeclared `sortable_fields` key to the config schema, removing schema validation warnings. ([#82](https://github.com/tag1consulting/scolta-drupal/pull/82))
- Synced `scolta.js`: a sort override no longer loses the subject filter, so "most expensive tooth" returns dental items by price rather than OR-matched common terms. ([#81](https://github.com/tag1consulting/scolta-drupal/pull/81))
- Synced `scolta.js`: sort-by-price uses Pagefind's native index-level sort instead of a client-side sort after BM25 truncation, which could not see items outside the top N. ([#80](https://github.com/tag1consulting/scolta-drupal/pull/80))

### Added
- Synced `scolta.js`: a `sort_hint` from the expand-query endpoint sorts results by the named metadata field, with a dismissible badge. ([#79](https://github.com/tag1consulting/scolta-drupal/pull/79))

## [1.0.0-rc3] - 2026-05-13

### Fixed
- Synced `scolta.js`: `expand_primary_weight` correctly weights original against expansion results. ([#77](https://github.com/tag1consulting/scolta-drupal/pull/77))

### Changed
- Replaced `strip_tags()` with `PlainTextOutput::renderFromHtml()` in `ScoltaContentGatherer` and `PagefindExporter`, which also decodes HTML entities. ([#73](https://github.com/tag1consulting/scolta-drupal/pull/73))
- Replaced raw PHP filesystem calls with `FileSystemInterface` across six files, with `phpcs:ignore` notes where a stream wrapper cannot be used. ([#73](https://github.com/tag1consulting/scolta-drupal/pull/73))
- Added `mglaman/phpstan-drupal` to `phpstan.neon`, where it had been a dev dependency but unconfigured. ([#73](https://github.com/tag1consulting/scolta-drupal/pull/73))
- Added PHPStan to CI as a dedicated job on PHP 8.3. ([#73](https://github.com/tag1consulting/scolta-drupal/pull/73))
- Added a `composer analyse` script for local PHPStan runs. ([#73](https://github.com/tag1consulting/scolta-drupal/pull/73))
- Added `.gitattributes` to keep dev-only files out of distribution archives. ([#73](https://github.com/tag1consulting/scolta-drupal/pull/73))
- Added a PHPStan baseline for pre-existing errors; `ScoltaCommands.php` and `ScoltaBackend.php` are excluded from analysis because they depend on optional runtime packages. ([#73](https://github.com/tag1consulting/scolta-drupal/pull/73))

### Tests
- Added `PlainTextConversionTest`, verifying `PlainTextOutput::renderFromHtml()` behavior and that `strip_tags()` is absent from production paths. ([#73](https://github.com/tag1consulting/scolta-drupal/pull/73))
- Added `FileSystemAbstractionTest`, verifying `FileSystemInterface` injection and use across all six affected files. ([#73](https://github.com/tag1consulting/scolta-drupal/pull/73))
- Extended `StructuralIntegrityTest` with PHPStan config validation, a `.gitattributes` coverage check, and a guard against raw filesystem calls re-entering `src/`. ([#73](https://github.com/tag1consulting/scolta-drupal/pull/73))

## [1.0.0-rc2] - 2026-05-12

### Fixed
- Synced `js/scolta.js` from canonical scolta-php: the `pagefindInstance` guard against WASM double-init, the `AUTO_LANGUAGE_FILTER` opt-in, and restored filter sidebar counts. ([#71](https://github.com/tag1consulting/scolta-drupal/pull/71))
- CI checksum enforcement is mandatory — the "SKIP: sha256 file not found" fallback is now a hard failure. ([#71](https://github.com/tag1consulting/scolta-drupal/pull/71))
- The admin "Rebuild" button uses the PHP pipeline when the indexer is `auto`, matching `drush scolta:build`; it previously probed for the binary and used a different pipeline. ([#70](https://github.com/tag1consulting/scolta-drupal/pull/70))
- Search API auto-rebuild queues a `scolta_rebuild` job for `auto` and `php` indexers, which it previously logged and skipped, so the index was never updated after content indexing. ([#70](https://github.com/tag1consulting/scolta-drupal/pull/70))
- The status report no longer warns about a missing Pagefind binary when the indexer is `auto`. ([#70](https://github.com/tag1consulting/scolta-drupal/pull/70))
- Excluded `vendor/drupal` from the release zip, since `drupal/core` and `drupal/search_api` are provided by the host installation. ([19d3020](https://github.com/tag1consulting/scolta-drupal/commit/19d3020a))
- Rewrote `README.md` with Scolta-specific installation, Drush, large-corpus and troubleshooting documentation. ([19d3020](https://github.com/tag1consulting/scolta-drupal/commit/19d3020a))
- Amazee auto-provisioning no longer overrides a user-configured API key: `_scolta_has_explicit_api_key()` now also checks the config-stored key, and `buildConfig()` falls back to Amazee credentials only when no explicit key exists. ([#69](https://github.com/tag1consulting/scolta-drupal/pull/69))

## [1.0.0-rc1] - 2026-05-11

First stable release — all features from 0.3.x promoted to the 1.0 API surface.

### Fixed
- Synced `js/scolta.js` for the `pagefindInstance` double-init guard, so behavior re-attachment no longer corrupts the Pagefind SharedWorker's WASM pointer for the tab session. ([1d800b8](https://github.com/tag1consulting/scolta-drupal/commit/1d800b8b))
- `ScoltaBackend::indexItems()` throws when a Pagefind binary build fails, instead of letting Search API mark everything indexed and drush exit 0 against an empty index. ([#66](https://github.com/tag1consulting/scolta-drupal/pull/66))
- Reconciled `js/scolta.js` with canonical scolta-php — the copy was 169 lines behind, missing multi-dimensional filters, multilingual index merging, language auto-filter, and URL filter state. ([#65](https://github.com/tag1consulting/scolta-drupal/pull/65))
- Added `cleanBrokenMarkdown()`, which repairs truncated AI summary markdown before rendering. ([#65](https://github.com/tag1consulting/scolta-drupal/pull/65))
- `build_dir` falls back to `public://scolta-build` when `private://` is unconfigured, which previously failed silently on standard installs. ([#63](https://github.com/tag1consulting/scolta-drupal/pull/63))
- Summarize no longer returns HTTP 400 on large result sets; the context string is truncated to 49,000 characters. ([#64](https://github.com/tag1consulting/scolta-drupal/pull/64))
- The settings page shows "Connected to Amazee.ai" when auto-provisioning has run, instead of falling through to "No API key configured". ([#61](https://github.com/tag1consulting/scolta-drupal/pull/61))
- "Index Now" no longer times out on shared hosting: the submit handler queries only entity IDs and defers all loading to the Batch API. ([#57](https://github.com/tag1consulting/scolta-drupal/pull/57))
- `site_name` falls back to the Drupal system site name across four pipeline paths, which otherwise indexed every `ContentItem` with an empty site name. ([#55](https://github.com/tag1consulting/scolta-drupal/pull/55))
- The WASM scoring module no longer 404s on subdirectory installs; the URL is built with `base_path()` ([#39](https://github.com/tag1consulting/scolta-drupal/issues/39)). ([#54](https://github.com/tag1consulting/scolta-drupal/pull/54))
- Added `drupal/search_api` to `composer.json` `require`, without which `drush en scolta` failed right after a fresh `composer require` ([#41](https://github.com/tag1consulting/scolta-drupal/issues/41)). ([#52](https://github.com/tag1consulting/scolta-drupal/pull/52))
- Search result URLs are no longer doubled on subdirectory installs; entity URLs are generated relative rather than absolute. ([#50](https://github.com/tag1consulting/scolta-drupal/pull/50))
- `getResolvedBuildDir()` and `getResolvedOutputDir()` no longer fatal when `private://` is unregistered; the URI string is returned as the path ([#35](https://github.com/tag1consulting/scolta-drupal/issues/35)). ([#48](https://github.com/tag1consulting/scolta-drupal/pull/48))
- `drush scolta:build --resume` starts at the manifest's `pages_processed` offset instead of DB offset 0, which had been overwriting correctly committed chunks. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- `ScoltaContentGatherer::gather()` loads entities in batches of 10 rather than 100, capping the per-batch allocator spike at ~2.5 MB instead of 25+ MB. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- The gather generator frees each entity with `array_shift` as it yields, instead of holding a whole batch alive in its stack frame. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- Search result links point at the real page URL: `resolveUrl()` strips the Pagefind base path that `fullUrl()` prepends. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- `drush scolta:build` no longer crashes with "Call to a member function realpath() on false"; `resolvePath()` guards the `getViaUri()` return and catches `\Throwable`. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- Decimal scoring values like `0.05` pass validation — scoring fields use `#step => 'any'` instead of `0.1`. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- The settings form carries `novalidate`, so number fields inside a collapsed `<details>` can no longer silently block Save and Rebuild Index. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- The site description field accepts up to 512 characters, up from Drupal's default 128. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))

### Added
- A CI step verifies the `js/scolta.js` checksum against canonical scolta-php, so a direct edit to the Drupal copy fails with a pointer to the source. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- Amazee.ai appears as a named option in the AI Provider dropdown, auto-selected when credentials are stored. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- The Amazee.ai API token is encrypted at rest in State with AES-256-CBC keyed from `hash_salt`; existing plain-text tokens are read transparently and re-encrypted on next store. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- The Amazee.ai trial is provisioned at module install, as a no-op when an explicit key or stored credentials already exist. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- `ScoltaSearchBlock` passes the current content language to `drupalSettings.scolta.currentLanguage`, so the frontend can pre-filter results to the page language. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- The Search API "Manage fields" form shows a notice when Scolta is the backend, since Scolta indexes the rendered entity rather than individual fields. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- The Search API backend form exposes the indexer mode (auto/PHP/binary), which had been settable only in config. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- After trial provisioning, the best available Claude models are applied to `ai_model` and `ai_expansion_model`, reported via a Messenger notice. ([#45](https://github.com/tag1consulting/scolta-drupal/pull/45))
- Timestamp-based rebuild optimization: `gather()` accepts a `TimestampManifest` and yields a `CachedContentReference` for an entity whose `changed` timestamp is unchanged, skipping the load entirely. ([#44](https://github.com/tag1consulting/scolta-drupal/pull/44))
- Amazee.ai integration for Drupal (phase 2): `DrupalConfigStorage` keeps LiteLLM tokens in State and out of config sync, `BudgetExceededHandler` warns admins once per 24 hours, and `AmazeeSettingsForm` provides the trial and upgrade connection paths. ([#34](https://github.com/tag1consulting/scolta-drupal/pull/34))
- `drush scolta:build` auto-resumes after a memory abort by spawning a fresh `--resume` run, so each process starts with clean RSS. ([#33](https://github.com/tag1consulting/scolta-drupal/pull/33))
- Added `drush scolta:finalize`, which merges pre-committed index chunks in a fresh PHP process for corpora too large to merge in-process. ([#31](https://github.com/tag1consulting/scolta-drupal/pull/31))

### Changed
- Added `extra.branch-alias` (`dev-main` → `1.0.x-dev`) so consumers can resolve this package with `^1.0@dev` from a VCS repository. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))
- `indexer: auto` now always uses the PHP indexer, which works on every host without `exec()` or Node.js; use `indexer: binary` for the old binary-first behavior. ([#26](https://github.com/tag1consulting/scolta-drupal/pull/26))
- `drush scolta:build --force` bypasses the per-item token cache as well as the fingerprint check, so it fully re-tokenizes every item. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))

### Documentation
- Added shared-hosting and large-corpus guidance to the README: SSH disconnect resilience, when to use `drush scolta:build`, and the `--resume`/`--restart`/`finalize` recovery flags. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))

### Tests
- Added delegation tests asserting `ScoltaSettingsForm::getDefaultPrompt()` uses `DefaultPrompts` rather than inline prompt copies. ([#67](https://github.com/tag1consulting/scolta-drupal/pull/67))

## [0.3.10] - 2026-05-05

### Fixed
- WASM merge URL lookup handles normalized URL formats via a multi-key map, preventing result stub fallback; misses are logged. ([#20](https://github.com/tag1consulting/scolta-drupal/pull/20))
- Lowered the title deduplication threshold to 0.6 Jaccard, with a secondary condition for short-title pairs sharing three or more words. ([#20](https://github.com/tag1consulting/scolta-drupal/pull/20))
- AI Overview markdown headings render as `<h3>`/`<h4>`/`<h5>` instead of displaying raw `#` characters. ([#20](https://github.com/tag1consulting/scolta-drupal/pull/20))
- The AI summary now describes post-expansion results: summarization is deferred until the expansion merge completes, with a `searchVersion` staleness check. ([#20](https://github.com/tag1consulting/scolta-drupal/pull/20))
- Relative URLs from the Pagefind index are absolutized before use in the summarize call and result links. ([#20](https://github.com/tag1consulting/scolta-drupal/pull/20))
- `stripHtml()` decodes HTML entities via DOM parsing, so titles and excerpts no longer show double-encoded entity strings. ([#20](https://github.com/tag1consulting/scolta-drupal/pull/20))

## [0.3.9] - 2026-05-02

### Added
- Added a "Site Type" selector to the settings form, built dynamically from `ScoltaConfig::getPresets()`; explicit scoring fields still override preset values. ([#18](https://github.com/tag1consulting/scolta-drupal/pull/18))

## [0.3.8] - 2026-05-01

### Added
- `ScoltaContentGatherer::gather()` indexes all translations, yielding one `ContentItem` per translation with its BCP-47 language code. ([#15](https://github.com/tag1consulting/scolta-drupal/pull/15))

### Fixed
- The admin rebuild passed `$outputDir` as `--output-path`, so the binary wrote the index one directory above where the reader looks and admin-UI rebuilds produced an index search never found. ([#17](https://github.com/tag1consulting/scolta-drupal/pull/17))
- The content gatherer uses `->processed` for formatted text fields instead of the raw stored `->value`, which could include JSON wrappers and unprocessed tokens. ([#15](https://github.com/tag1consulting/scolta-drupal/pull/15))

## [0.3.7] - 2026-04-30

### Fixed
- JS/CSS/WASM assets are committed to the package rather than generated at install, which left them missing whenever the module was installed as a dependency. ([78df1d7](https://github.com/tag1consulting/scolta-drupal/commit/78df1d73))

### Improved
- Documentation: clearer use-case descriptions for enterprise Drupal deployments. ([8c75814](https://github.com/tag1consulting/scolta-drupal/commit/8c758145))

## [0.3.6] - 2026-04-29

### Fixed
- Phrase proximity scoring works for multi-word queries: `computeContentWordLocations` derives real word positions from `data.content` instead of trusting Pagefind's `data.locations`. ([8056a01](https://github.com/tag1consulting/scolta-drupal/commit/8056a01b))

### Added
- Added the `ai_expansion_model` config key (default empty), an optional model used for query expansion only. ([#14](https://github.com/tag1consulting/scolta-drupal/pull/14))

## [0.3.5] - 2026-04-28

### Changed
- Lowered the default `expand_primary_weight` to 0.5, giving AI-expanded terms more influence on intent-based queries. ([#13](https://github.com/tag1consulting/scolta-drupal/pull/13))
- Raised the default `ai_summary_top_n` to 10, so the AI sees more results and curates better for constraint queries. ([#13](https://github.com/tag1consulting/scolta-drupal/pull/13))
- Raised the default `ai_summary_max_chars` to 4000, giving the larger `ai_summary_top_n` enough excerpt content to work with. ([#13](https://github.com/tag1consulting/scolta-drupal/pull/13))

## [0.3.4] - 2026-04-27

### Fixed
- Replaced `uniqid('scolta_notice_', TRUE)` with `bin2hex(random_bytes(8))` in `ScoltaBatchOperations`, avoiding period-containing IDs that break downstream sanitizers. ([#12](https://github.com/tag1consulting/scolta-drupal/pull/12))
- `PagefindExporter` now checks `file_put_contents` for `false` and throws, instead of silently dropping index artifacts. ([#12](https://github.com/tag1consulting/scolta-drupal/pull/12))
- `ScoltaRebuildWorker` logs failed fingerprint writes instead of failing silently. ([#12](https://github.com/tag1consulting/scolta-drupal/pull/12))
- Added `JSON_THROW_ON_ERROR` to the GitHub API `json_decode` in `ScoltaCommands`, so a malformed response logs an error rather than producing a null release object. ([#12](https://github.com/tag1consulting/scolta-drupal/pull/12))
- Added source-parse tests preventing reintroduction of `uniqid(..., TRUE)`, unchecked `file_put_contents`, and unguarded `json_decode` on remote responses. ([#12](https://github.com/tag1consulting/scolta-drupal/pull/12))

### Added
- Added `ScoltaCacheBehaviorTest`, covering the `DrupalCacheDriver` contract and end-to-end handler caching. ([#11](https://github.com/tag1consulting/scolta-drupal/pull/11))
- Closed config test gaps by adding five scoring keys to `scoringOverrideProvider`. ([#10](https://github.com/tag1consulting/scolta-drupal/pull/10))
- Added `testAiLanguagesPropagateToJsScoringConfig`, covering multi-language `ai_languages` through to `toJsScoringConfig()`. ([adcf1196](https://github.com/tag1consulting/scolta-drupal/commit/adcf1196))
- Added `language` and `recency_strategy` to `scoringOverrideProvider`, confirming they propagate through `ScoltaConfig`. ([6181d0e](https://github.com/tag1consulting/scolta-drupal/commit/6181d0ec))

## [0.3.3] - 2026-04-26

### Changed
- `buildWithPhpIndexer()` delegates budget and intent construction to scolta-php's `MemoryBudgetConfig` and `BuildIntentFactory`, removing duplicated precedence logic. ([d9136a5](https://github.com/tag1consulting/scolta-drupal/commit/d9136a5a))
- Raised the gather batch size from 50 to 100 entities per page load, matching the WordPress and Laravel adapters. ([d9136a5](https://github.com/tag1consulting/scolta-drupal/commit/d9136a5a))
- `DrushProgressReporter::advance()` sets the Symfony progress bar message, making chunk detail visible in verbose Drush output. ([d9136a5](https://github.com/tag1consulting/scolta-drupal/commit/d9136a5a))
- The three AI controllers use scolta-php's `AiControllerTrait`, removing a duplicated seven-argument instantiation block from each. ([d9136a5](https://github.com/tag1consulting/scolta-drupal/commit/d9136a5a))
- Added an `antipatterns` CI job asserting `orchestrator->build()` is always called with a logger. ([1685da3](https://github.com/tag1consulting/scolta-drupal/commit/1685da33))
- Bumped the scolta-php dependency to `^0.3.3` (atomic manifest writes, CRC32 chunk validation, stale lock detection). ([e9bc375](https://github.com/tag1consulting/scolta-drupal/commit/e9bc3750))

## [0.3.2] - 2026-04-24

Coordinated release. Ports the streaming gather and CLI wiring pattern from scolta-wp to Drupal.

### Fixed
- `buildWithPhpIndexer()` passed neither a logger nor a progress reporter to the orchestrator, leaving the CLI silent through large builds; added `DrushProgressReporter`. ([b4e5441](https://github.com/tag1consulting/scolta-drupal/commit/b4e5441f))
- `ScoltaContentGatherer::gather()` became a `\Generator` paginating in batches of 50 with a `resetCache()` per batch, instead of materializing every entity's field data in RAM at once. ([b4e5441](https://github.com/tag1consulting/scolta-drupal/commit/b4e5441f))
- Lint fixes: removed an unused import and corrected alignment, a missing use statement and a missing `@return` description. ([b4e5441](https://github.com/tag1consulting/scolta-drupal/commit/b4e5441f))

### Added
- `drush scolta:build` accepts `--memory-budget` as a profile name or a raw byte value, plus a `--chunk-size` flag; both persist as admin settings. ([#8](https://github.com/tag1consulting/scolta-drupal/pull/8))
- Added `ScoltaContentGatherer::gatherCount()`, a COUNT-only query used for early exit and build sizing without loading field data. ([b4e5441](https://github.com/tag1consulting/scolta-drupal/commit/b4e5441f))

### Changed
- CI pulls scolta-php at `@dev` rather than the stale `consolidation-0.3.0` branch. ([b4e5441](https://github.com/tag1consulting/scolta-drupal/commit/b4e5441f))

## [0.3.1] - 2026-04-23

### Fixed
- The release workflow triggers on both `v0.x.x` and bare `0.x.x` tags, fixing the 0.3.0 release that shipped with no binary assets. ([376daec](https://github.com/tag1consulting/scolta-drupal/commit/376daec1))

### Added
- Added a `validate-zip` CI job asserting the release archive contains `vendor/autoload.php` and `scolta.module`. ([376daec](https://github.com/tag1consulting/scolta-drupal/commit/376daec1))
- Added a Memory Budget fieldset to the settings form, which shows the detected PHP `memory_limit` inline and warns when the selected profile exceeds 70% of it. ([376daec](https://github.com/tag1consulting/scolta-drupal/commit/376daec1))

## [0.3.0] - 2026-04-23

### Added
- Added the `--memory-budget` option to `drush scolta:build`, taking `conservative` (default), `balanced` or `aggressive`. ([#5](https://github.com/tag1consulting/scolta-drupal/pull/5))
- Added `--resume`, which continues a previously interrupted PHP index build. ([#5](https://github.com/tag1consulting/scolta-drupal/pull/5))
- Added `--restart`, which discards interrupted state and forces a clean rebuild. ([#5](https://github.com/tag1consulting/scolta-drupal/pull/5))

### Changed
- Rewrote `buildWithPhpIndexer()` on top of `IndexBuildOrchestrator::build()`, from 85 lines to about 30. ([#5](https://github.com/tag1consulting/scolta-drupal/pull/5))
- Extracted path resolution into a private `resolvePath()` helper. ([#5](https://github.com/tag1consulting/scolta-drupal/pull/5))
- Inherits the scolta-php 0.3.0 improvements: `MemoryBudget`, `BuildIntent`, `BuildCoordinator`, the streaming pipeline, and the OOM fix. ([b4e00ce](https://github.com/tag1consulting/scolta-drupal/commit/b4e00ce7))

### Fixed
- `drush scolta:status` shows an `--- Indexer ---` section with active-indexer selection matching the Laravel and WordPress adapters. ([#4](https://github.com/tag1consulting/scolta-drupal/pull/4))

## [0.2.4] - 2026-04-21

### Added
- Added Playwright layout tests asserting `.scolta-layout` fills at least 90% of a 1440px viewport in both single- and two-column modes, wired into CI. ([eaa85bb](https://github.com/tag1consulting/scolta-drupal/commit/eaa85bbf))
- Admin rebuild notices persist across page loads until each admin dismisses them, tracked per user via `user.data`. ([eaa85bb](https://github.com/tag1consulting/scolta-drupal/commit/eaa85bbf))
- Added a Drupal functional test job to CI, booting a real Drupal 11 install so the full HTTP render pipeline is covered. ([831449a](https://github.com/tag1consulting/scolta-drupal/commit/831449a3))
- Added `RouteSmokeFunctionalTest`, which reads `scolta.routing.yml` at runtime and smoke-tests every route, so a new route is covered without updating a test list. ([831449a](https://github.com/tag1consulting/scolta-drupal/commit/831449a3))
- Added a static-analysis guard asserting every `fromRoute('scolta.*')` call names a route that exists. ([831449a](https://github.com/tag1consulting/scolta-drupal/commit/831449a3))

### Changed
- Inherits the scolta-php 0.2.4 fixes: phrase-proximity scoring, the WASM config key fix, quoted-phrase forced mode, and a second WASM rebuild. ([30b6e86](https://github.com/tag1consulting/scolta-drupal/commit/30b6e869))

### Fixed
- The results layout fills the full width: `.scolta-layout` defaults to a single column, and the 220px filter sidebar column only applies under `.has-filters`. ([#2](https://github.com/tag1consulting/scolta-drupal/pull/2))

## [0.2.3] - 2026-04-17

### Fixed
- `hook_install()` copies the compiled JS/CSS/WASM from scolta-php into the module directory, since Composer's `post-install-cmd` runs only for the root package. ([21675d4](https://github.com/tag1consulting/scolta-drupal/commit/21675d4f))
- `ScoltaSearchBlock` points `pagefindPath` at `{output_dir}/pagefind/pagefind.js`, where the binary actually writes its output. ([b5362fd](https://github.com/tag1consulting/scolta-drupal/commit/b5362fd2))
- All four rebuild paths invalidate the `scolta_search_index` cache tag, so the search block updates without a manual cache flush. ([b5362fd](https://github.com/tag1consulting/scolta-drupal/commit/b5362fd2))

### Changed
- Inherits the scolta-php 0.2.3 fixes: filter sidebar, N-set merge, AI context, PII sanitization, and priority pages. ([21675d4](https://github.com/tag1consulting/scolta-drupal/commit/21675d4f))

## [0.2.2] - 2026-04-16

### Added
- Added a scoring language select with 30 ISO 639-1 options, stored as `scoring.language`. ([ae71ba9](https://github.com/tag1consulting/scolta-drupal/commit/ae71ba98))
- Added a custom stop words textarea (`scoring.custom_stop_words`) for comma-separated additional stop words. ([ae71ba9](https://github.com/tag1consulting/scolta-drupal/commit/ae71ba98))
- Added a recency strategy select — `exponential`, `linear`, `step`, `none` or `custom` (`scoring.recency_strategy`). ([ae71ba9](https://github.com/tag1consulting/scolta-drupal/commit/ae71ba98))
- Added a custom recency curve textarea taking JSON `[[days, boost], …]` control points, shown only when the strategy is `custom`. ([ae71ba9](https://github.com/tag1consulting/scolta-drupal/commit/ae71ba98))
- Updated the config schema and install defaults for all four new fields. ([ae71ba9](https://github.com/tag1consulting/scolta-drupal/commit/ae71ba98))

## [0.2.1] - 2026-04-15

### Fixed
- Security: the configured Pagefind binary path is validated against an allowlist before reaching `Process`, preventing command injection through a compromised config value. ([0ac426f](https://github.com/tag1consulting/scolta-drupal/commit/0ac426fe))

## [0.2.0] - 2026-04-13

### Fixed
- Added `hook_requirements()`, which warns in the Status Report when the Pagefind binary is absent and the PHP fallback indexer is active. ([8904ffa](https://github.com/tag1consulting/scolta-drupal/commit/8904ffa3))

### Added
- `hook_install()` queues an initial index build and explains how to build immediately via Drush. ([add9b81](https://github.com/tag1consulting/scolta-drupal/commit/add9b818))
- The search block checks for the index on disk: admins see a warning linking to the build, non-admins see nothing until it is ready. ([add9b81](https://github.com/tag1consulting/scolta-drupal/commit/add9b818))
- Added a "Rebuild Index" button to the settings form, triggering an immediate rebuild via the Batch API (PHP indexer) or synchronous binary execution. ([feb54f6](https://github.com/tag1consulting/scolta-drupal/commit/feb54f6a))
- PHP indexer rebuilds from the admin UI run through Drupal's Batch API (`ScoltaBatchOperations`), processing content in chunks so large sites do not time out. ([feb54f6](https://github.com/tag1consulting/scolta-drupal/commit/feb54f6a))
- Added the `ScoltaRebuildWorker` queue worker (`scolta_rebuild` queue), which processes rebuild requests during cron under a lock preventing concurrent builds. ([feb54f6](https://github.com/tag1consulting/scolta-drupal/commit/feb54f6a))
- `hook_entity_insert()` and `hook_entity_update()` enqueue a rebuild when nodes are saved and `pagefind.auto_rebuild` is enabled. ([feb54f6](https://github.com/tag1consulting/scolta-drupal/commit/feb54f6a))
- `hook_uninstall()` cleans up the rebuild queue, the build lock, and state entries. ([feb54f6](https://github.com/tag1consulting/scolta-drupal/commit/feb54f6a))
- Added PHP indexer integration: `scolta:build` can index in memory via `Tag1\Scolta\Index\PhpIndexer`, with no Pagefind binary required. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- Added the `--indexer` option (`php`, `binary`, `auto`) to `scolta:build`, overriding the `indexer` config setting. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- Added the `indexer` config key (`auto`/`php`/`binary`) to `scolta.settings.yml` with a matching schema entry. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- Under `indexer: auto` the build command uses the Pagefind binary when available and otherwise falls back to the PHP indexer. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- Added `--force` to `scolta:build`, which skips the content fingerprint check and rebuilds regardless. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- Added content fingerprint tracking in a `.scolta-state` file, so an unchanged corpus skips the rebuild. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- Added `wasmPath` to `drupalSettings.scolta`, pointing at the WASM glue served from the module directory. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- Added the `ai_languages` setting for multilingual AI responses, configurable from the admin form as comma-separated language codes. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- All AI controllers pass `aiLanguages` from config to `AiEndpointHandler`. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- Added the `PromptEnrichEvent` Symfony event, dispatched before AI prompts are sent to the LLM provider. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- Added `EventDrivenEnricher`, bridging scolta-php's `PromptEnricherInterface` to Drupal's event system. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))
- All AI controllers inject the event dispatcher and pass the enricher to `AiEndpointHandler`. ([cf2f79e](https://github.com/tag1consulting/scolta-drupal/commit/cf2f79e5))

### Removed
- Removed the Extism/FFI dependency: every reference to `ScoltaWasm`, `ExtismCheck`, `Tag1\Scolta\Wasm`, FFI and Extism is gone, and scolta-php is now pure PHP with no native extensions. ([e199f42](https://github.com/tag1consulting/scolta-drupal/commit/e199f42b))
- Removed the FFI extension from the CI workflow. ([e199f42](https://github.com/tag1consulting/scolta-drupal/commit/e199f42b))
- Removed `continue-on-error` from the CI lint step, so lint failures are caught. ([e199f42](https://github.com/tag1consulting/scolta-drupal/commit/e199f42b))
- Removed `testScoltaPhpWasmPathUsesUnderscores`, since `ScoltaWasm.php` no longer exists in scolta-php. ([e199f42](https://github.com/tag1consulting/scolta-drupal/commit/e199f42b))
- Removed the `isExtismAvailable()` helper and its skip logic from `ScoltaSettingsFormTest`, now that `toJsScoringConfig()` is pure PHP. ([e199f42](https://github.com/tag1consulting/scolta-drupal/commit/e199f42b))

### Changed
- Scoring, merging and query expansion parsing now happen entirely in the browser via WASM, with `wasmPath` injected into `drupalSettings` so `scolta.js` can load the glue module. ([9050322](https://github.com/tag1consulting/scolta-drupal/commit/90503222))
- The WASM assets (`scolta_core.js`, `scolta_core_bg.wasm`) are copied to `js/wasm/` by the `copy-assets` composer script. ([9050322](https://github.com/tag1consulting/scolta-drupal/commit/90503222))
- `scolta:build` pre-resolves and caches all prompt templates after building the index, reducing runtime overhead for the API endpoints. ([9050322](https://github.com/tag1consulting/scolta-drupal/commit/90503222))
- Prompt resolution uses pure PHP (`DefaultPrompts::resolve()`) instead of WASM calls. ([9050322](https://github.com/tag1consulting/scolta-drupal/commit/90503222))
- Updated PHPDoc to remove stale WASM/FFI/Extism references. ([e199f42](https://github.com/tag1consulting/scolta-drupal/commit/e199f42b))

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

[1.4.0]: https://github.com/tag1consulting/scolta-drupal/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/tag1consulting/scolta-drupal/compare/v1.2.0...v1.3.0
[1.1.0]: https://github.com/tag1consulting/scolta-drupal/compare/v1.0.5...v1.1.0
[1.0.1]: https://github.com/tag1consulting/scolta-drupal/compare/1.0.0...1.0.1
[1.0.0-rc4]: https://github.com/tag1consulting/scolta-drupal/compare/1.0.0-rc3...1.0.0-rc4
[1.0.0-rc3]: https://github.com/tag1consulting/scolta-drupal/compare/1.0.0-rc2...1.0.0-rc3
[1.0.0-rc2]: https://github.com/tag1consulting/scolta-drupal/compare/1.0.0-rc1...1.0.0-rc2
[1.0.0-rc1]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.10...1.0.0-rc1
[0.3.10]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.9...0.3.10
[0.3.9]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.8...0.3.9
[0.3.8]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.7...0.3.8
[0.3.7]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.6...0.3.7
[0.3.6]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.5...0.3.6
[0.3.5]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.4...0.3.5
[0.3.4]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.3...0.3.4
[0.3.3]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.2...0.3.3
[0.3.2]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.1...0.3.2
[0.3.1]: https://github.com/tag1consulting/scolta-drupal/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/tag1consulting/scolta-drupal/compare/0.2.4...0.3.0
[0.2.4]: https://github.com/tag1consulting/scolta-drupal/compare/0.2.3...0.2.4
[0.2.3]: https://github.com/tag1consulting/scolta-drupal/compare/0.2.2...0.2.3
[0.2.2]: https://github.com/tag1consulting/scolta-drupal/compare/0.2.1...0.2.2
[0.2.1]: https://github.com/tag1consulting/scolta-drupal/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/tag1consulting/scolta-drupal/releases/tag/0.2.0
