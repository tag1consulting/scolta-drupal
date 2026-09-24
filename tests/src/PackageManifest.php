<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Symfony\Component\Yaml\Yaml;

/**
 * The routing, services and permission manifests of both shipped modules.
 *
 * This package ships scolta and scolta_ui from one repository, and most of
 * what the integrity tests assert is a package-level contract: that the route
 * /api/scolta/v1/summarize exists and requires 'use scolta ai', that
 * scolta.ai_service is defined and receives the cache — not which of the two
 * .yml files happens to declare it. Reading both and merging keeps those
 * assertions about the contract, so a later move between the modules does not
 * silently drop a test that still passes against an empty half.
 *
 * Where a test is about the split itself — that a route lives in one module
 * and not the other — it reads the individual file, and should.
 */
final class PackageManifest {

  /**
   * The repository root.
   */
  public static function root(): string {
    return dirname(__DIR__, 2);
  }

  /**
   * Every module manifest of the given kind, keyed by module name.
   *
   * @param string $kind
   *   A manifest suffix: 'routing', 'services', 'permissions', 'links.menu'.
   *
   * @return array<string, array>
   *   The parsed manifests, keyed by owning module. A module that ships no
   *   manifest of this kind is absent rather than present and empty.
   */
  public static function each(string $kind): array {
    $files = [
      'scolta' => self::root() . '/scolta.' . $kind . '.yml',
      'scolta_ui' => self::root() . '/modules/scolta_ui/scolta_ui.' . $kind . '.yml',
    ];

    $manifests = [];
    foreach ($files as $module => $file) {
      if (is_file($file)) {
        $manifests[$module] = Yaml::parseFile($file) ?? [];
      }
    }

    return $manifests;
  }

  /**
   * Every PHP source file in both shipped modules.
   *
   * The guards that sweep the codebase — no FFI, no bare filesystem calls, no
   * second derivation of the API key — have to see both modules or they stop
   * guarding half of it silently, passing because there is nothing left in
   * src/ to catch.
   *
   * @return array<string, string>
   *   File contents, keyed by path relative to the repository root.
   */
  public static function sourceFiles(): array {
    $files = [];

    foreach (['/src', '/modules/scolta_ui/src'] as $directory) {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(self::root() . $directory, \FilesystemIterator::SKIP_DOTS)
      );
      foreach ($iterator as $file) {
        if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
          continue;
        }
        $relative = str_replace(self::root() . '/', '', $file->getPathname());
        $files[$relative] = (string) file_get_contents($file->getPathname());
      }
    }

    ksort($files);

    return $files;
  }

  /**
   * Every namespace-less PHP file both modules ship.
   *
   * .module and .install files are the package's blind spot: phpstan analyses
   * .php only, and no phpcs sniff resolves a class name, so a missing import
   * in one of these is invisible to both gates and fatal at runtime — a
   * .module file has no namespace, so an unimported Foo::BAR resolves to \Foo
   * and throws "Class not found" on the first request that runs it.
   *
   * @return array<string, string>
   *   File contents, keyed by path relative to the repository root.
   */
  public static function proceduralFiles(): array {
    $paths = [
      'scolta.module',
      'scolta.install',
      'modules/scolta_ui/scolta_ui.module',
      'modules/scolta_ui/scolta_ui.install',
    ];

    $files = [];
    foreach ($paths as $relative) {
      $absolute = self::root() . '/' . $relative;
      if (is_file($absolute)) {
        $files[$relative] = (string) file_get_contents($absolute);
      }
    }

    return $files;
  }

  /**
   * The install defaults of both settings objects, merged.
   *
   * scolta.settings and scolta_ui.settings partition one former object, so a
   * test asking "does this package ship a default for sayt_enabled" wants the
   * union. A test asking which of the two owns a key reads the file itself —
   * that is what ScoltaConfigPartitionTest does.
   */
  public static function settings(): array {
    return array_merge(
      Yaml::parseFile(self::root() . '/config/install/scolta.settings.yml') ?? [],
      Yaml::parseFile(self::root() . '/modules/scolta_ui/config/install/scolta_ui.settings.yml') ?? [],
    );
  }

  /**
   * The schema mappings of both settings objects, merged.
   */
  public static function settingsSchema(): array {
    $backend = Yaml::parseFile(self::root() . '/config/schema/scolta.schema.yml');
    $frontend = Yaml::parseFile(self::root() . '/modules/scolta_ui/config/schema/scolta_ui.schema.yml');

    // Keep the top-level shape callers expect — a 'scolta.settings' key whose
    // 'mapping' holds every key the package ships — while leaving the backend
    // plugin's own schema object where it is.
    $merged = $backend;
    $merged['scolta.settings']['mapping'] = array_merge(
      $backend['scolta.settings']['mapping'],
      $frontend['scolta_ui.settings']['mapping'],
    );
    $merged['scolta_ui.settings'] = $frontend['scolta_ui.settings'];

    return $merged;
  }

  /**
   * The documented example values of both settings objects, merged.
   */
  public static function exampleSettings(): array {
    return array_merge(
      Yaml::parseFile(self::root() . '/config/scolta.settings.example.yml') ?? [],
      Yaml::parseFile(self::root() . '/modules/scolta_ui/config/scolta_ui.settings.example.yml') ?? [],
    );
  }

  /**
   * The install defaults of both settings objects, as text.
   */
  public static function rawSettings(): string {
    return file_get_contents(self::root() . '/config/install/scolta.settings.yml') . "\n"
      . file_get_contents(self::root() . '/modules/scolta_ui/config/install/scolta_ui.settings.yml');
  }

  /**
   * The schemas of both settings objects, as text.
   */
  public static function rawSettingsSchema(): string {
    return file_get_contents(self::root() . '/config/schema/scolta.schema.yml') . "\n"
      . file_get_contents(self::root() . '/modules/scolta_ui/config/schema/scolta_ui.schema.yml');
  }

  /**
   * Both modules' manifests of one kind, as text.
   *
   * For the assertions that are about how a definition is written rather than
   * about what it parses to — that a service argument list contains
   * '@cache.default', that a route path is spelled a particular way.
   *
   * @param string $kind
   *   A manifest suffix, as for each().
   */
  public static function raw(string $kind): string {
    $text = '';
    foreach (self::each($kind) as $module => $unused) {
      $file = $module === 'scolta'
        ? self::root() . '/scolta.' . $kind . '.yml'
        : self::root() . '/modules/' . $module . '/' . $module . '.' . $kind . '.yml';
      $text .= file_get_contents($file) . "\n";
    }
    return $text;
  }

  /**
   * Every route the package defines, keyed by route name.
   */
  public static function routes(): array {
    return array_merge(...array_values(self::each('routing')) ?: [[]]);
  }

  /**
   * Every service the package defines, keyed by service ID.
   */
  public static function services(): array {
    $services = [];
    foreach (self::each('services') as $manifest) {
      $services += $manifest['services'] ?? [];
    }
    return $services;
  }

  /**
   * Every permission the package defines, keyed by permission name.
   */
  public static function permissions(): array {
    return array_merge(...array_values(self::each('permissions')) ?: [[]]);
  }

  /**
   * Every asset library the package defines, keyed by library name.
   *
   * Library names are module-scoped in the reference (scolta_ui/search), but
   * the keys inside a .libraries.yml are bare, so these come back bare too.
   */
  public static function libraries(): array {
    return array_merge(...array_values(self::each('libraries')) ?: [[]]);
  }

}
