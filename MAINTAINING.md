# Maintaining scolta-drupal

The Drupal module over scolta-php. Publishes to drupal.org.

Everything true of more than one Scolta repo lives in
[scolta-core/MAINTAINING.md](https://github.com/tag1consulting/scolta-core/blob/main/MAINTAINING.md):
the version rules, the release order, the fleet checks, the rules every repo shares. How the bundle is
copied and checked is in
[scolta-core/ASSETS.md](https://github.com/tag1consulting/scolta-core/blob/main/ASSETS.md).

**What it is.** A Drupal module, glue only. It depends on `scolta-php` and never on `scolta-core`
directly.

**Where the version comes from.** A release takes its version from the git tag. On drupal.org the
packager derives the released version from that tag and injects it into `scolta.info.yml` at packaging
time, so the tag rather than the file is the source of truth for a release. The `version:` committed in
`scolta.info.yml` is what a git or dev checkout reports before packaging: keep it roughly current and
treat it as a fallback rather than the record. Never add a `version` key to `composer.json`: the
`version-consistency` job hard-fails if one appears, because the drupal.org Composer facade honours a
declared version and a site tracking a dev branch could then `composer update` but never
`composer install` from the resulting lock.

**Where it publishes.** drupal.org, through packages.drupal.org, as `drupal/scolta`. Not Packagist: a
`tag1/scolta-drupal` there would collide with the module name. To confirm: the packages.drupal.org page
shows the version, with `version` and `version_normalized` agreeing.

**CI checks.** phpunit (`test`, `functional`, `playwright`, `coverage`), `phpstan`, `assets-in-sync`
(the model bundle check: `cmp` each committed asset against `tag1/scolta-php` resolved from `dev-main`),
`docs-check` (CHANGELOG when code changes), `version-consistency` (no `composer.json` version key, plus
an advisory `scripts/validate-release.php`), `version-sync`, `lock-guard`, `dist-archive`,
`antipatterns`, and `Version coherence`. `upstream-preview` is informational and deliberately not a merge
gate. The scolta-php floor is covered by `tests/src/ScoltaPhpFloorTest.php`.

**On release day.** This section is incomplete: it waits on the `scripts/validate-release.php` decision
(shared guide, Still open #1). The known steps: bump `scolta.info.yml`. Push both tags to GitHub, and
only to GitHub: `vX.Y.Z`, and `X.Y.Z` without the `v` for drupal.org, which ignores `v`-prefixed tags.
Advance the devel branch on GitHub too: `git fetch origin`, then `git push origin origin/main:X.Y.x`, a
placeholder because drupal.org names the devel branch after the minor line (skip it and the branch sits
stale a whole cycle). The pull mirror carries both to git.drupalcode.org; verify with `git ls-remote`
against drupalcode rather than assuming. Then create the release node by hand on drupal.org; the notes
must be HTML, not markdown, so draft them for review rather than pasting markdown. Tags and branches
reach drupalcode automatically through the mirror; the release node is the manual part. Full procedure,
including what to do when the mirror hasn't moved, is in the shared
[RELEASING.md](https://github.com/tag1consulting/scolta-core/blob/main/RELEASING.md).

**Watch out for.**

- Never push to git.drupalcode.org, on any branch or tag: it breaks the pull mirror. Push `origin/main`
  after `git fetch origin`, not your local `main`, and read "Everything up-to-date" as a sign the pull
  request wasn't merged.
- This package carries the bundle at `js/`, `css/` and `js/wasm/`. Re-vendor with `composer copy-assets`
  and commit the result. When `assets-in-sync` is red because the matching scolta-php PR hasn't merged,
  do not run `composer copy-assets` to green it: that overwrites the new bundle with the old one,
  which is the exact failure the check exists to catch.
- `phpstan` needs a raised memory limit. CI passes `--memory-limit=512M` explicitly, and the
  `composer analyse` script does not, so running it locally the composer way can OOM on unmodified
  `main`. That OOM is not a signal about your branch; raise the limit and re-run before you read it as
  one.
