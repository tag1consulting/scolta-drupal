# Upgrade notes

Breaking changes and the action each one requires, newest first. A release that
needs nothing from you is not listed here; see `CHANGELOG.md` for the full
record.

## Unreleased

### The module split into `scolta` and `scolta_ui`

**Who is affected:** every site. `drush updatedb` does everything required;
what needs attention is exported configuration and any code that referenced
the moved parts by name.

**What changed:** this package now ships two modules. `scolta` builds the index
and serves its files. `scolta_ui` renders search in the browser, owns scoring,
display and the AI tier, and provides the settings screen for all of it.
Neither depends on the other, so a site can enable either alone — which is what
lets one site build an index that other, thin sites consume.

**What to do:**

1. Run `drush updatedb`. `scolta_update_10009()` enables `scolta_ui`, moves the
   query-time settings out of `scolta.settings` into the new
   `scolta_ui.settings`, and grants the new `administer scolta ui` permission
   to every role that already held `administer scolta`. Both origin settings
   initialize to `<local>`, so the site keeps building and serving locally with
   no behaviour change.
2. If you manage configuration through exported YAML, run `drush cex` and
   commit both `scolta.settings.yml` and the new `scolta_ui.settings.yml`. The
   moved keys will show as removed from the first and added to the second.
3. If you set query-time keys with `drush config:set`, in `settings.php`, or
   through a config split, change the object name. `scolta.settings` keeps
   `entity_types`, `body_fields`, `sortable_fields`, `filter_fields`,
   `field_mappings`, `memory_budget`, `pagefind`, `incremental` and `cleanup`;
   everything else is now `scolta_ui.settings`.
4. If a custom module or theme referenced moved classes, change the namespace:
   `ScoltaSearchBlock`, `ScoltaSettingsForm`, `AmazeeSettingsForm`,
   `ScoltaAiService`, `AssetDeployer`, the AI controllers, `PromptEnrichEvent`
   and the `Drupal\scolta\Access` classes are all `Drupal\scolta_ui\...` now.
   A prompt-enricher module subscribing to `PromptEnrichEvent` must update its
   `use` statement, and should declare `scolta:scolta_ui` as its dependency.
   **Service IDs and route names did not change** — `scolta.ai_service`,
   `scolta.ai_access` and the `scolta.settings` route all keep their names, so
   a decorator or a `Url::fromRoute()` call needs no edit.
5. If anything attached the asset libraries directly, they are `scolta_ui/search`
   and `scolta_ui/drupal_bridge` now.
6. The index settings moved to their own screen at
   `/admin/config/search/scolta/index`, behind `administer scolta`.
   `/admin/config/search/scolta` keeps everything query-time, behind
   `administer scolta ui`.

**What did not change:** the API paths (`/api/scolta/v1/...`), the block plugin
ID (`scolta_search`), the `use scolta ai` permission, and the behaviour of a
site that enables both modules.

### The AI tier's Drush commands moved to `scolta_ui`

**Who is affected:** scripts and monitors that call `scolta:clear-cache`,
`scolta:check-setup`, or read the `ai_provider` section of `scolta:status`.

**What changed:** those commands belong to `scolta_ui`, so a site with only the
backend no longer has them. `scolta:status` reports the index and build only;
the `ai_provider` and `cache` sections are `drush scolta:ai-status`
(`--format=json` works as it does for `scolta:status`).

**What to do:** point anything that parsed `scolta:status` for the AI provider
at `scolta:ai-status`.

### A build no longer pre-warms the AI prompt cache

**Who is affected:** sites with custom AI prompts, marginally.

**What changed:** resolving and caching the AI prompts used to happen at the end
of every index build. It is now `drush scolta:cache-prompts`, because resolved
prompts are query-time state and a site that only renders search has no build to
hang them off.

**What to do:** nothing. The endpoints resolve and cache on first use, so the
only difference is one slightly slower first AI request after a prompt change.
Add `drush scolta:cache-prompts` to a deploy routine if that matters.

### Search API and the Pagefind binary are gone; the PHP indexer is the only pipeline

**Who is affected:** every site. A site whose index was defined through a
Search API index (its datasources and bundles) needs to move that selection
into `scolta.settings`. A site that set `indexer: binary` loses that mode.

**What changed:** `scolta.settings: entity_types` is the only definition of
what is indexed. The `scolta_pagefind` Search API backend, the HTML export and
the Pagefind CLI pipeline (`drush scolta:export`, `scolta:rebuild-index`,
`scolta:download-pagefind`, `scolta:build --indexer/--output-dir/--skip-pagefind`)
are removed, along with the `indexer`, `pagefind.binary` and
`pagefind.view_mode` config keys and the `search_api` dependency. The
auto-rebuild debounce moved from the Search API server's *Rebuild delay* to
`scolta.settings: pagefind.auto_rebuild_delay`.
The module now requires `tag1/scolta-php` `^2.0`, the release that removes the
binary pipeline from the library; `composer update tag1/scolta-php` brings it
in with the module.

**What to do:** run `drush updb`. `scolta_update_10008()` deletes the Search
API index and server that used the Scolta backend, copies the server's rebuild
delay into `pagefind.auto_rebuild_delay`, and uninstalls `search_api` when no
other server, index or module still uses it. If your Search API index selected
specific bundles, set them before the next build, e.g.
`drush config:set --input-format=yaml scolta.settings entity_types '{node: [article, page]}'`;
the default indexes every `node` bundle. Then `drush scolta:build`.

**Exported config recreates what the update deleted.** `drush deploy` runs
`config:import` right after `updb`, so a sync directory that still holds the
Scolta `search_api.server.*` and `search_api.index.*` files puts the server
and index back the moment the update has removed them. The module keeps an
inert `scolta_pagefind` backend so that import does not fail, but the
recreated index tracks content and does nothing with it on every cron run,
and `search_api` stays installed. Delete those two files from the sync
directory (and the `indexer`, `pagefind.binary` and `pagefind.view_mode` keys
from `scolta.settings.yml`, or re-export it after the update) and deploy;
the import then deletes the recreated entities. Until that is done the status
report at `/admin/reports/status` warns about the leftover server. When
`search_api` is no longer used by anything else, uninstall it by hand:
`drush pm:uninstall search_api`.

### The browser bundle moved from the module directory to public files

**Who is affected:** every site, but the standard deploy routine already does
everything required. Sites with custom tooling that referenced the old asset
paths need to update them.

**What changed:** `js/scolta.js`, `css/scolta.css` and the WASM pair are no
longer shipped inside the module. They are copied from the installed
`tag1/scolta-php` into `public://scolta-assets` at module install, by update
hook, and on every cache rebuild.

**What to do:** run `drush updb` and `drush cr` after `composer update`, as
usual — either one performs the first deployment. Until one of them runs, the
search page's JS/CSS references point at files that no longer exist, which is
the ordinary "rebuild caches after a code update" requirement, not a new one.
Anything that hardcoded `modules/.../scolta/js/scolta.js` (CSP rules, asset
pipelines, aggregation exclusions) should now reference the public files path.

### `ScoltaContentGatherer::gather()` changed its fourth parameter

**Who is affected:** anybody who subclasses `ScoltaContentGatherer`, decorates
the `scolta.content_gatherer` service, or otherwise declares a class carrying
the old signature. Callers are not affected: the parameter is optional and its
position is unchanged.

**What changed:**

```php
// Before (1.1.x)
public function gather(string $entityType, string $bundle, string $siteName, int $startPage = 0, ?TimestampManifest $manifest = NULL, bool $force = FALSE): \Generator

// After (1.2.0)
public function gather(string $entityType, string $bundle, string $siteName, int|string|NULL $resumeFromId = NULL, ?TimestampManifest $manifest = NULL, bool $force = FALSE): \Generator
```

The parameter stopped being a page offset and became an entity ID boundary.
Those units were never the same: the manifest counts pages, the gatherer's
cursor walks entities, and one entity yields one page per translation, so a
resume on a translated corpus skipped the wrong rows.

**Why this cannot degrade quietly:** PHP resolves a signature-compatibility
violation when the subclass is **defined**, not when the method is called. A
subclass or decorator still declaring `int $startPage = 0` is a fatal error at
class load, on every request that autoloads it, whether or not anything calls
`gather()`. No shim can run ahead of a class-load failure, so there is nothing
this module could have added to soften it.

**What to do:** update the override to the new signature and treat the argument
as an entity ID rather than an offset. Pass `NULL` to start from the beginning;
the boundary is applied inclusively, because the entity at the boundary may have
had only some of its translations indexed before the previous segment stopped.

### Inherited from scolta-php 1.2.0

This release requires `tag1/scolta-php` `^1.2.0`, which carries two breaks of its
own: the `AmazeeCredentials` constructor signature changed, and the `aiProvider`
default changed. Neither is re-exported by this module's own API, but a site with
custom code calling into scolta-php directly is exposed to both. See scolta-php's
1.2.0 upgrade notes for the detail and the required changes.

Note that this module also stops shipping a default AI provider: the install
default for `ai_provider` is now empty and nothing coalesces an empty value back
to `anthropic`. This is going-forward only. There is no update hook, and an
existing site keeps whatever provider it has saved.
