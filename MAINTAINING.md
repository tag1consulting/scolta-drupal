# MAINTAINING — scolta-drupal

The Drupal module over scolta-php. Publishes to drupal.org.

Everything true of more than one Scolta repo lives in
[scolta-core/MAINTAINING.md](https://github.com/tag1consulting/scolta-core/blob/main/MAINTAINING.md):
the version rules, the release order, the fleet checks, the rules every repo shares. How the bundle is
copied and checked is in
[scolta-core/ASSETS.md](https://github.com/tag1consulting/scolta-core/blob/main/ASSETS.md).

**What it is.** A Drupal module, glue only. It depends on `scolta-php` and never on `scolta-core`
directly.

**Where the version lives.** `scolta.info.yml`, and nowhere else. **Never add a `version` key to
`composer.json`**: the `version-consistency` job hard-fails if one appears, because the drupal.org
Composer facade honours a declared version and a site tracking a dev branch could then `composer update`
but never `composer install` from the resulting lock. drupal.org injects the release version into
`scolta.info.yml` at packaging time.

**Where it publishes.** drupal.org, through packages.drupal.org, as `drupal/scolta`. Not Packagist: a
`tag1/scolta-drupal` there would collide with the module name. To confirm: the packages.drupal.org page
shows the version, with `version` and `version_normalized` agreeing.

**CI checks.** phpunit (`test`, `functional`, `playwright`, `coverage`), `phpstan`, `assets-in-sync`
(the model bundle check: `cmp` each committed asset against `tag1/scolta-php` resolved from `dev-main`),
`docs-check` (CHANGELOG when code changes), `version-consistency` (no `composer.json` version key, plus
an advisory `scripts/validate-release.php`), `version-sync`, `lock-guard`, `dist-archive`,
`antipatterns`, and `Version coherence`. `upstream-preview` is informational and deliberately not a merge
gate. The scolta-php floor is covered by `tests/src/ScoltaPhpFloorTest.php`.

**On release day** — *still open, waiting on the `scripts/validate-release.php` decision (shared guide,
Still open #1).* The known steps: bump `scolta.info.yml`. Push two tags: `vX.Y.Z` on GitHub, and `X.Y.Z`
without the `v` for drupal.org, which ignores `v`-prefixed tags. `git fetch origin`, then
`git push drupal origin/main:X.Y.Z`. Fast-forward the devel branch with `git push drupal origin/main:1.0.x`
(skip it and the branch sits stale a whole cycle). Create the release node by hand on drupal.org; the
notes must be HTML, not markdown, so draft them for review rather than pasting markdown. Nothing about
drupal.org is automated: the GitHub tag only cuts a GitHub release.

**Watch out for.**

- Don't push the `v`-tag to drupal (that was the old 1.0.0 way). Push `origin/main`, not your local
  `main`. Confirm the mirror moved with `git ls-remote --heads`: "Everything up-to-date" usually means
  the PR wasn't merged.
- This package carries the bundle at `js/`, `css/` and `js/wasm/`. Re-vendor with `composer copy-assets`
  and commit the result. When `assets-in-sync` is red because the matching scolta-php PR hasn't merged,
  do **not** run `composer copy-assets` to green it: that overwrites the new bundle with the old one,
  which is the exact failure the check exists to catch.
- `phpstan` needs a raised memory limit. CI passes `--memory-limit=512M` explicitly, and the
  `composer analyse` script does not, so running it locally the composer way can OOM on unmodified
  `main`. That OOM is not a signal about your branch; raise the limit and re-run before you read it as
  one.
