# Upgrade notes

Breaking changes and the action each one requires, newest first. A release that
needs nothing from you is not listed here; see `CHANGELOG.md` for the full
record.

## Unreleased

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
