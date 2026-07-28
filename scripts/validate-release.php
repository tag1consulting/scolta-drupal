<?php

/**
 * @file
 * Validate scolta-drupal is ready for release.
 *
 * `scolta.info.yml` is the single source of the module version.
 *
 * There used to be two locations, the second being a "version" key in
 * composer.json, and this script asserted the two matched. That key is gone:
 * declaring "version" in a package published from version control overrides
 * the version Composer derives from the branch or tag, which is what the
 * extra.branch-alias beside it exists to describe. The drupal.org Composer
 * facade honours the declared string, so `drupal/scolta` presented itself as
 * a fixed "1.0.6-dev" whatever branch it came from, and a consuming site
 * constrained to dev-1.0.x could `composer update` but never `composer
 * install` from the resulting lock.
 *
 * drupal.org injects the release version into scolta.info.yml at packaging
 * time, so info.yml is both what the packaging pipeline writes and what
 * Drupal itself reads. Nothing needs the version declared in composer.json.
 */

// scolta.info.yml is the only place the version is declared.
$infoYml = file_get_contents(__DIR__ . '/../scolta.info.yml');
preg_match('/^version:\s*[\'"]?([^\s\'"]+)/m', $infoYml, $m);
$infoVersion = $m[1] ?? 'MISSING';

echo "scolta.info.yml:  {$infoVersion}\n";

$fail = FALSE;

if ($infoVersion === 'MISSING') {
  echo "FAIL: scolta.info.yml has no version key\n";
  $fail = TRUE;
}

if (str_ends_with($infoVersion, '-dev')) {
  echo "FAIL: Version ends in -dev\n";
  $fail = TRUE;
}

// A hardcoded "version" must not come back. It is the defect this script was
// rewritten around, and it is a one-line edit to reintroduce.
$composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), TRUE);
if (array_key_exists('version', $composer)) {
  echo "FAIL: composer.json declares a \"version\" key.\n";
  echo "      Remove it. Composer derives the version from the branch or tag;\n";
  echo "      extra.branch-alias describes the mapping. A declared version\n";
  echo "      overrides both, and the drupal.org facade honours it, which\n";
  echo "      breaks `composer install` on a site tracking dev-1.0.x.\n";
  $fail = TRUE;
}

if (!$fail) {
  echo "PASS: scolta.info.yml declares {$infoVersion}, composer.json declares no version.\n";
}
else {
  exit(1);
}
