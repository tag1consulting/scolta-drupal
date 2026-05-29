<?php

/**
 * @file
 * Validate scolta-drupal is ready for release.
 *
 * Drupal has TWO places where the version must match:
 * 1. composer.json "version" field
 * 2. scolta.info.yml "version" field.
 */

// 1. composer.json
$composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), TRUE);
$composerVersion = $composer['version'] ?? 'MISSING';

// 2. scolta.info.yml
$infoYml = file_get_contents(__DIR__ . '/../scolta.info.yml');
preg_match('/^version:\s*[\'"]?([^\s\'"]+)/m', $infoYml, $m);
$infoVersion = $m[1] ?? 'MISSING';

echo "composer.json:    {$composerVersion}\n";
echo "scolta.info.yml:  {$infoVersion}\n";

$fail = FALSE;

if ($composerVersion === 'MISSING' || $infoVersion === 'MISSING') {
  echo "FAIL: One or more version locations are missing\n";
  $fail = TRUE;
}

if ($composerVersion !== $infoVersion) {
  echo "FAIL: Versions don't match across the two locations\n";
  $fail = TRUE;
}

if (str_ends_with($composerVersion, '-dev')) {
  echo "FAIL: Version ends in -dev\n";
  $fail = TRUE;
}

if (!$fail) {
  echo "PASS: Both locations match: {$composerVersion}\n";
}
else {
  exit(1);
}
