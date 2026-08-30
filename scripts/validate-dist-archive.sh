#!/usr/bin/env bash
#
# Validate the drupal.org distribution tarball.
#
# Drupal.org builds the canonical project tarball from `git archive` semantics
# on its GitLab mirror, honoring `.gitattributes export-ignore`. Nothing else in
# CI looks at what actually lands in that tarball, so a typo'd export-ignore line
# (or a new dev file nobody thought to ignore) silently ships developer cruft to
# every site that installs via Composer / the d.o packaging pipeline; an
# over-broad line silently DROPS a runtime asset and ships a broken module.
#
# Precedent: the scolta-wp 13 MB zip incident and the WordPress.org dist-cruft
# flags. This is the change-control gate for the Drupal tarball.
#
# This script reproduces the d.o `git archive` build and asserts:
#   1. EXCLUDED   - no export-ignored path is present in the archive.
#   2. RUNTIME    - every committed runtime asset IS present.
#   3. TOP-LEVEL  - every top-level entry is on an explicit allowlist (fail-closed
#                   change-control: a new top-level file/dir fails until it is
#                   either export-ignored or added to the allowlist below).
#   4. SIZE       - the archive is under a documented cap.
#
# Run locally from the repo root:  scripts/validate-dist-archive.sh
#
# The single source of truth for what gets excluded is `.gitattributes`
# (export-ignore lines); the single source of truth for what is allowed at the
# top level is ALLOWED_TOP_LEVEL below. Keep those two in sync with reality.

set -euo pipefail

# Resolve repo root from this script's location so it runs from anywhere.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

ARCHIVE="${1:-/tmp/scolta-drupal-dist.tar}"
EXTRACT_DIR="$(mktemp -d "${TMPDIR:-/tmp}/scolta-drupal-dist.XXXXXX")"

# ---------------------------------------------------------------------------
# Configuration: keep in sync with .gitattributes and the runtime tree.
# ---------------------------------------------------------------------------

# Paths that MUST NOT appear in the archive. Mirror of the `export-ignore`
# lines in .gitattributes (the filter that is supposed to drop them).
# If you add an export-ignore line, add it here too (and vice versa).
EXCLUDED_PATHS=(
  ".github"
  ".ddev"
  "tests"
  "phpstan.neon"
  "phpstan-baseline.neon"
  "phpunit.xml"
  "phpcs.xml.dist"
  ".gitattributes"
  ".gitignore"
  ".editorconfig"
  "CLAUDE.md"
  "MAINTAINING.md"
  "scripts"
  # Development and demo aid: applying it rewrites scolta.settings and places a
  # block, which is not something a release tarball should offer a live site.
  "recipes"
)

# Committed runtime assets that MUST be present in the archive. A broken or
# over-broad export-ignore line that drops one of these ships a dead module.
REQUIRED_PATHS=(
  "composer.json"
  "scolta.info.yml"
  "scolta.install"
  "scolta.module"
  "scolta.libraries.yml"
  "scolta.routing.yml"
  "scolta.services.yml"
  "scolta.permissions.yml"
  "scolta.links.menu.yml"
  "scolta.api.php"
  "drush.services.yml"
  "js/scolta-drupal-bridge.js"
  "config/install/scolta.settings.yml"
  "config/schema/scolta.schema.yml"
  "config/scolta.settings.example.yml"
)

# Fail-closed top-level allowlist, derived from the current clean archive.
# Every top-level entry in the tarball must be one of these. A new top-level
# file or directory fails CI until it is either export-ignored (add it to
# .gitattributes + EXCLUDED_PATHS) or deliberately shipped (add it here).
ALLOWED_TOP_LEVEL=(
  "CHANGELOG.md"
  "LICENSE"
  "README.md"
  "UPGRADE.md"
  "composer.json"
  "config"
  "drush.services.yml"
  "js"
  "scolta.api.php"
  "scolta.info.yml"
  "scolta.install"
  "scolta.libraries.yml"
  "scolta.links.menu.yml"
  "scolta.module"
  "scolta.permissions.yml"
  "scolta.routing.yml"
  "scolta.services.yml"
  "src"
)

# Size cap. The browser bundle (JS/CSS/WASM, ~1.5 MB) no longer ships in the
# tarball — it deploys from the installed tag1/scolta-php at runtime — leaving
# a clean archive of 983,040 bytes (git archive, 2026-08-21). Cap set at
# roughly 2x to catch accidental bloat (e.g. a vendored binary, a stray index,
# a re-committed bundle) while tolerating normal growth. Bump deliberately
# with a fresh measurement if the module grows.
MAX_BYTES=2000000

cleanup() { rm -rf "$EXTRACT_DIR"; }
trap cleanup EXIT

FAIL=0
fail() { echo "FAIL: $*" >&2; FAIL=1; }

# ---------------------------------------------------------------------------
# Build / locate the archive.
# ---------------------------------------------------------------------------
if [ "${BUILD_ARCHIVE:-1}" = "1" ]; then
  echo "Building archive: git archive HEAD -o $ARCHIVE"
  rm -f "$ARCHIVE"
  git archive HEAD -o "$ARCHIVE"
fi

if [ ! -f "$ARCHIVE" ]; then
  echo "FAIL: archive not found at $ARCHIVE (set BUILD_ARCHIVE=1 to build it)" >&2
  exit 1
fi

echo "Extracting $ARCHIVE -> $EXTRACT_DIR"
tar -xf "$ARCHIVE" -C "$EXTRACT_DIR"

# Sorted, slash-stripped top-level entries actually present in the archive.
# (Read into an array without mapfile so this works on bash 3.2, e.g. macOS.)
ARCHIVE_TOP=()
while IFS= read -r line; do
  ARCHIVE_TOP+=("$line")
done < <(tar -tf "$ARCHIVE" | awk -F/ 'NF { print $1 }' | sort -u)

in_list() {
  local needle="$1"; shift
  local item
  for item in "$@"; do
    [ "$item" = "$needle" ] && return 0
  done
  return 1
}

# ---------------------------------------------------------------------------
# 1. EXCLUDED-FILES ASSERT
# ---------------------------------------------------------------------------
echo
echo "== 1. Excluded paths must be absent =="
for p in "${EXCLUDED_PATHS[@]}"; do
  if tar -tf "$ARCHIVE" | grep -qE "^${p}(/|\$)"; then
    fail "export-ignored path '$p' LEAKED into the dist archive."
    echo "      -> The filter lives in .gitattributes (export-ignore line for /$p). Check for a typo or missing line." >&2
  else
    echo "  ok absent: $p"
  fi
done

# ---------------------------------------------------------------------------
# 2. RUNTIME-PRESENCE ASSERT
# ---------------------------------------------------------------------------
echo
echo "== 2. Runtime assets must be present =="
for p in "${REQUIRED_PATHS[@]}"; do
  if [ -e "$EXTRACT_DIR/$p" ]; then
    echo "  ok present: $p"
  else
    fail "required runtime asset '$p' is MISSING from the dist archive."
    echo "      -> An over-broad export-ignore line in .gitattributes may be dropping it, or it was never committed." >&2
  fi
done

# ---------------------------------------------------------------------------
# 3. FAIL-CLOSED TOP-LEVEL SWEEP
# ---------------------------------------------------------------------------
echo
echo "== 3. Top-level change-control sweep (fail-closed) =="
for entry in "${ARCHIVE_TOP[@]}"; do
  if in_list "$entry" "${ALLOWED_TOP_LEVEL[@]}"; then
    echo "  ok allowed: $entry"
  else
    fail "UNEXPECTED top-level entry '$entry' in the dist archive."
    echo "      -> Either export-ignore it (.gitattributes + EXCLUDED_PATHS in this script)" >&2
    echo "         or, if it is meant to ship, add it to ALLOWED_TOP_LEVEL in scripts/validate-dist-archive.sh." >&2
  fi
done

# ---------------------------------------------------------------------------
# 4. SIZE CAP
# ---------------------------------------------------------------------------
echo
echo "== 4. Size cap =="
BYTES=$(wc -c < "$ARCHIVE" | tr -d ' ')
echo "  archive size: $BYTES bytes (cap: $MAX_BYTES bytes)"
if [ "$BYTES" -gt "$MAX_BYTES" ]; then
  fail "dist archive is $BYTES bytes, over the $MAX_BYTES-byte cap."
  echo "      -> Something large leaked (vendored binary, search index, build artifact?). Inspect: tar -tvf $ARCHIVE | sort -k3 -n | tail" >&2
fi

echo
if [ "$FAIL" -ne 0 ]; then
  echo "DIST ARCHIVE VALIDATION FAILED." >&2
  exit 1
fi
echo "DIST ARCHIVE VALIDATION PASSED."
