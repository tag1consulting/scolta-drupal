<?php

/**
 * @file
 * PHPUnit bootstrap that adapts to where this checkout lives.
 *
 * The unit suite needs only the Composer autoloader; the functional suite
 * needs Drupal core's test bootstrap, whose location depends on layout.
 * Trying core first means one phpunit.xml serves both suites: in a
 * `ddev poser`-built project (docroot web/ beside this checkout) or a
 * consuming site (module under web/modules), core's bootstrap loads and
 * every suite can run; in a plain checkout with no core, the autoloader
 * alone loads and only the unit suite can run.
 */

declare(strict_types=1);

$candidates = [
  // Project-root layout: a ddev poser-built project, docroot web/.
  __DIR__ . '/../web/core/tests/bootstrap.php',
  // Consuming-site layout: module at <docroot>/modules/<anything>/scolta.
  __DIR__ . '/../../../../core/tests/bootstrap.php',
];
foreach ($candidates as $candidate) {
  if (file_exists($candidate)) {
    require $candidate;
    return;
  }
}
require __DIR__ . '/../vendor/autoload.php';
