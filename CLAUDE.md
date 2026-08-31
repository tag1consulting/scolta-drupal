# Claude Rules for scolta-drupal

## Versioning (CRITICAL — read scolta-core/VERSIONING.md)

Each Scolta package versions independently, from its own git tags. Compatibility with scolta-php is expressed by the caret constraint in `composer.json`, not by matching version numbers with it — and that constraint is the only statement this repository makes about which scolta-php it needs. **No `composer.lock` is committed here.** This is a library, not a deployed application: drupal.org ships the git tarball, a consuming site resolves scolta-php against its own lock, and every CI job resolves with `composer update`, so a committed lock governed nothing but added a file that a path repository made machine-specific. This is a platform adapter — it depends on scolta-php, never on scolta-core directly.

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

### Local Development ###

We use DDEV and [a few custom commands](.ddev/commands/web) from the https://github.com/ddev/ddev-drupal-contrib/ add-on.

  - `ddev phpunit`
  - `ddev phpcs`
  - `ddev phpstan`
  - `ddev eslint`
  - `ddev stylelint`
  - `dddev poser`
  - `ddev symlink-project`

  `ddev poser` should be run instead of `composer install`. `ddev symlink-project` should always follow `ddev poser`.

### Local cross-package development

To test against un-released scolta-php locally, run `composer config minimum-stability dev && composer require tag1/scolta-php:@dev` (the path repo then supplies the dev build). Both edits are local-only: `minimum-stability` must stay `stable` in the committed `composer.json`, and there is no lock to accidentally commit.

The constraint is the coordination gate. While the adapter needs an unreleased scolta-php the floor carries a `@dev` suffix (e.g. `^1.5@dev`), which reaches the development branch from a root that asks for it; `release.yml`'s `constraint-guard` refuses to publish while the floor is a development constraint or while no published stable release satisfies it. So scolta-drupal 1.5.0 cannot ship before scolta-php 1.5.0 exists. Drop the `@dev` suffix when it does.

### Drupal conventions

- Use Drupal coding standards (no `declare(strict_types=1)` in .module files, but use it in classes).
- Services are defined in `scolta.services.yml` — service argument count MUST match constructor parameter count.
- Config schema (`config/schema/`) MUST match install defaults (`config/install/`).
- Route controllers MUST exist and have the referenced methods.

## Browser assets — deployed from vendor, never committed

The browser bundle (`js/scolta.js`, `css/scolta.css`, `wasm/scolta_core.js`, `wasm/scolta_core_bg.wasm`) is canonical in `scolta-php/assets/` and is **not committed to this repository**. `Drupal\scolta\Service\AssetDeployer` copies it from the installed `tag1/scolta-php` into `public://scolta-assets` at module install and on every cache rebuild (`hook_rebuild()`), copying only files that differ from the vendored canonical. `scolta.libraries.yml` and `ScoltaSearchBlock` reference the deployed copies.

Why this design, in one paragraph of history: the bundle used to be committed here (drupal.org ships the git tarball, and Composer does not run a dependency's scripts, so nothing else placed a web-accessible copy). That required a re-vendor commit (`composer copy-assets`, since removed) for every scolta-php bundle change and an `assets-in-sync` CI job to catch stale copies — and on coordinated changes the job sat red until upstream merged. Deploying from vendor at cache-rebuild time removes the whole class: `composer update` + `drush cr` is sufficient, staleness is impossible by construction, and the public files directory is writable even on immutable-code hosts (Pantheon, Acquia prod) where the module directory is not. Vendor itself cannot be referenced directly because it sits above the docroot.

Rules that follow:

- **Never commit a copy of the bundle here** (`StructuralIntegrityTest::testNoBrowserBundleFilesAreCommitted` enforces this). All bundle changes go to scolta-php; this repo picks them up from whatever `composer update` resolves the caret constraint to.
- **Never remove `hook_rebuild()` or the install-time deploy** — `hook_install()` runs once per site ever, so the rebuild hook is the only thing keeping updating sites current.
- `AssetDeploymentFunctionalTest` is the behavioral guard: install deploys byte-identical copies, a cache rebuild repairs a stale one, uninstall removes the directory.

## Testing

- Unit suite (the default): `./vendor/bin/phpunit`. Runs without a Drupal bootstrap — YAML parsing and reflection.
- Kernel (KernelTestBase) and functional (BrowserTestBase) suites: `ddev phpunit --testsuite kernel,functional`, from a `ddev poser`-built project. Same command CI runs. Prefer kernel over functional when no HTTP request is involved — a real container and database at a fraction of the cost.
- Only write valuable tests that exercise important functionality, and assert on behavior — return values, filesystem effects, logged records. When the class under test only touches a couple of Drupal interfaces, construct it directly with stubbed services (see `ScoltaCacheBehaviorTest`). When it needs a real container — a plugin built via a plugin manager, a service with several collaborators, a hook — prefer a `tests/src/Kernel/*` test over hand-stubbing (see `ScoltaRebuildWorkerKernelTest`, which replaced ~450 lines of core-class stubs with the real container); reach for `tests/src/Functional/*` (`BrowserTestBase`) only when the behavior needs an actual HTTP request — see `CronCleanupFunctionalTest`. Don't make assertions about a method's source code or config-file text; some older tests here do that, and they are not a pattern to copy. A PR without a test is acceptable for trivial PRs.
- WASM-dependent tests are covered by scolta-php, not this package.

## Documentation Rules

Documentation follows code. When a PR changes behavior, the same PR must update the relevant docs.

- **CHANGELOG.md**: Every PR that changes code (not docs-only) SHOULD add an brief entry under `## [Unreleased]` unless its change is trivial.
- **README.md**: Update if the change affects installation, Drush commands, API endpoints, permissions, or configuration.
- **Config schema**: `config/schema/scolta.schema.yml` MUST stay in sync with `config/install/scolta.settings.yml` and the settings form.
- **PHPDoc**: All public methods SHOULD have complete PHPDoc including `@since` and `@stability`.
