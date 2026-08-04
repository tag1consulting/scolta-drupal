# Claude Rules for scolta-drupal

## Versioning (CRITICAL — read scolta-core/VERSIONING.md)

Major versions are synchronized across all Scolta packages; minor and patch versions are released independently per package. Adapters pin scolta-php via `composer.lock` within their `^1.x` constraint. This is a platform adapter — it depends on scolta-php, never on scolta-core directly.

### Rules

- **NEVER** reimplement scoring, HTML cleaning, or prompt logic. These belong in scolta-core via scolta-php.
- **NEVER** change `composer.json` to depend on `tag1/scolta-core`. Depend on `tag1/scolta-php`.
- Dependency constraint MUST be a caret constraint: `"tag1/scolta-php": "^X.Y"` (or `@dev` for development).
- If scolta-php adds a new method you need, bump the minimum constraint (e.g., `^1.5`).
- All public methods SHOULD have `@since` and `@stability` annotations.

### Version management and -dev workflow

`scolta.info.yml` holds the module version, and it is the **only** place it is declared. It is always either a tagged release (`0.2.0`) or a dev pre-release (`0.3.0-dev`). See scolta-core/VERSIONING.md for the full workflow.

- If current version has `-dev`, **do not change it** — multiple commits accumulate on one dev version.
- If current version is a bare release and you're making the first change after it, bump to next target with `-dev`.
- **WARNING:** Never commit a bare version bump without tagging it as a release.

**NEVER add a `version` field to `composer.json`.** CI fails if one appears. A declared version overrides the version Composer derives from the branch or tag, which is what the `extra.branch-alias` beside it exists to describe. Packagist ignores it; the drupal.org Composer facade does not, so the package announced itself as a fixed `1.0.6-dev` regardless of branch. A site constrained to `drupal/scolta: dev-1.0.x` could then `composer update` but never `composer install` from the resulting lock (`Required package "drupal/scolta" is in the lock file as "1.0.6-dev" but that does not satisfy your constraint "dev-1.0.x"`), so it installed on a developer's machine and failed in CI on every clean checkout. drupal.org injects the release version into `scolta.info.yml` at packaging time.

### Local cross-package development

To test against un-released scolta-php locally, run `composer config minimum-stability dev && composer require tag1/scolta-php:@dev` (the path repo then supplies the dev build). **Do not commit a lock resolved from the path repo** — it describes one developer's machine, and the CI lock guard rejects `dist.type=path` on every branch.

The lock does not have to name a stable scolta-php on a branch, and while the floor is `^1.2@dev` it cannot: no stable release satisfies `^1.2`. The committed lock names `dev-main` from Packagist, `composer validate` in CI keeps it agreeing with `composer.json`, and `release.yml` refuses to publish while it is a development version. That is the gate: scolta-drupal 1.2.0 cannot be released before scolta-php 1.2.0 exists. Drop the `@dev` suffix from the constraint and re-lock when it does.

### Drupal conventions

- Use Drupal coding standards (no `declare(strict_types=1)` in .module files, but use it in classes).
- Services are defined in `scolta.services.yml` — service argument count MUST match constructor parameter count.
- Config schema (`config/schema/`) MUST match install defaults (`config/install/`).
- Route controllers MUST exist and have the referenced methods.

## Vendored browser assets — DO NOT EDIT DIRECTLY

Four files are copies of canonical sources in `scolta-php/assets/`:

| committed here | canonical in scolta-php |
|---|---|
| `js/scolta.js` | `assets/js/scolta.js` |
| `css/scolta.css` | `assets/css/scolta.css` |
| `js/wasm/scolta_core.js` | `assets/wasm/scolta_core.js` |
| `js/wasm/scolta_core_bg.wasm` | `assets/wasm/scolta_core_bg.wasm` |

**Never edit them in this repo.** All changes go to scolta-php first, then the copies are re-vendored here. The duplication is a requirement, not a smell: a site installing `drupal/scolta` gets the drupal.org tarball built from git, and Composer does not run a dependency's scripts, so nothing copies anything at install time. **The committed file is the shipped file.**

### Re-vendoring after a scolta-php change

**Assets are NOT refreshed as a side effect of `composer install` or `composer update`.** They used to be, via `post-install-cmd` / `post-update-cmd`, and that is precisely what made the CI parity check vacuous: the hook rewrote the tracked file from `vendor/` moments before the check compared the two, so the check could never fail on a stale committed copy. A fixer and a checker in the same pipeline is the bug class, and the fixer always wins. Re-vendoring a bundle is a deliberate act that lands in the CHANGELOG, so it is a command a human runs and CI notices when someone forgot.

1. Bump `tag1/scolta-php` in `composer.json` / `composer.lock` as needed.
2. Run `composer copy-assets`. It overwrites all four committed files from `vendor/tag1/scolta-php/assets/` and fails loudly if a source is missing.
3. Commit the result, with a CHANGELOG entry describing what changed in the bundle.
4. The `assets-in-sync` CI job byte-compares each committed file against the vendored canonical and fails if any differs.

On a coordinated change, `assets-in-sync` goes red until the matching scolta-php pull request merges, because it resolves scolta-php from `dev-main`. That is correct signal, not a problem to work around: an adapter must not merge ahead of its upstream. **Do not run `composer copy-assets` to make it green** — that overwrites the new bundle with the old one.

## Testing

- Run: `./vendor/bin/phpunit`
- Tests run without a Drupal bootstrap — they use YAML parsing and reflection.
- WASM-dependent tests are covered by scolta-php, not this package.

## Documentation Rules

Documentation follows code. When a PR changes behavior, the same PR must update the relevant docs.

- **CHANGELOG.md**: Every PR that changes code (not docs-only) MUST add an entry under `## [Unreleased]`. CI enforces this.
- **README.md**: Update if the change affects installation, Drush commands, API endpoints, permissions, or configuration.
- **Config schema**: `config/schema/scolta.schema.yml` MUST stay in sync with `config/install/scolta.settings.yml` and the settings form.
- **PHPDoc**: All public methods SHOULD have complete PHPDoc including `@since` and `@stability`.
