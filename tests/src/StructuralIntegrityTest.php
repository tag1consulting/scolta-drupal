<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates that service definitions, routes, and PHP files are consistent.
 *
 * These tests do not require a Drupal bootstrap — they verify that the
 * wiring in YAML files references PHP classes/methods that actually exist.
 */
class StructuralIntegrityTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  // -------------------------------------------------------------------
  // Service classes exist.
  // -------------------------------------------------------------------

  public function testServiceClassFilesExist(): void {
    $services = ['services' => PackageManifest::services()];

    foreach ($services['services'] as $id => $def) {
      if (!isset($def['class'])) {
        continue; // logger.channel.scolta uses parent.
      }
      $classFile = $this->classToFile($def['class']);
      $this->assertFileExists(
        $classFile,
        "Service '{$id}' references class {$def['class']} but file does not exist"
      );
    }
  }

  public function testDrushCommandClassFileExists(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $class = $drush['services']['scolta.commands']['class'];
    $classFile = $this->classToFile($class);
    $this->assertFileExists($classFile,
      "Drush command class {$class} file does not exist");
  }

  // -------------------------------------------------------------------
  // Routing controller classes and methods exist.
  // -------------------------------------------------------------------

  #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
  public function testRouteControllerFileExists(string $routeName, string $controllerSpec): void {
    if (str_contains($controllerSpec, '::')) {
      [$class, $method] = explode('::', $controllerSpec);
    } else {
      $class = ltrim($controllerSpec, '\\');
      $method = null;
    }

    $classFile = $this->classToFile($class);
    $this->assertFileExists($classFile,
      "Route '{$routeName}' references {$class} but file does not exist");

    // Verify the method exists in the file source — directly or inherited
    // from the local AiApiControllerBase (the shared AI request pipeline).
    if ($method) {
      $contents = file_get_contents($classFile);
      if (!str_contains($contents, "function {$method}(")
        && str_contains($contents, 'extends AiApiControllerBase')) {
        $contents = file_get_contents(dirname($classFile) . '/AiApiControllerBase.php');
      }
      $this->assertStringContainsString(
        "function {$method}(",
        $contents,
        "Route '{$routeName}' references method {$method} not found in {$class} or its base"
      );
    }
  }

  public static function routeProvider(): array {
    $root = dirname(__DIR__, 2);
    $routing = PackageManifest::routes();
    $routes = [];

    foreach ($routing as $name => $def) {
      if (isset($def['defaults']['_controller'])) {
        $routes[$name] = [$name, ltrim($def['defaults']['_controller'], '\\')];
      }
      if (isset($def['defaults']['_form'])) {
        $routes[$name] = [$name, ltrim($def['defaults']['_form'], '\\')];
      }
    }

    return $routes;
  }

  // -------------------------------------------------------------------
  // PHP use-statements reference classes that exist in scolta-php or Drupal.
  // -------------------------------------------------------------------

  public static function phpFileProvider(): \Generator {
    foreach (PackageManifest::sourceFiles() as $relative => $unused) {
      yield $relative => [PackageManifest::root() . '/' . $relative];
    }
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('phpFileProvider')]
  public function testScoltaPhpImportsReferenceRealClasses(string $file): void {
    $scoltaPhpSrc = $this->resolveScoltaPhpSrc();
    if ($scoltaPhpSrc === null) {
      $this->markTestSkipped('scolta-php source not available at sibling or vendor path');
    }

    $contents = file_get_contents($file);

    // Extract all use statements referencing Tag1\Scolta.
    preg_match_all('/^use\s+(Tag1\\\\Scolta\\\\[^;]+);/m', $contents, $matches);

    if (empty($matches[1])) {
      // File does not import any Tag1\Scolta classes — nothing to check.
      $this->assertTrue(true);
      return;
    }

    foreach ($matches[1] as $fqcn) {
      // Convert FQCN to expected file path under scolta-php.
      $relative = str_replace('\\', '/', str_replace('Tag1\\Scolta\\', '', $fqcn));
      $expectedFile = $scoltaPhpSrc . $relative . '.php';

      $this->assertFileExists($expectedFile,
        "File {$file} imports {$fqcn} but {$expectedFile} does not exist");
    }
  }

  // -------------------------------------------------------------------
  // Service argument count matches constructor parameter count.
  // -------------------------------------------------------------------

  public function testServiceArgumentCountMatchesConstructor(): void {
    $services = ['services' => PackageManifest::services()];

    $classesToCheck = [
      'scolta.ai_service' => 'Drupal\scolta_ui\Service\ScoltaAiService',
      'scolta.pagefind_exporter' => 'Drupal\scolta\Service\PagefindExporter',
      'scolta.pagefind_builder' => 'Drupal\scolta\Service\PagefindBuilder',
      'scolta.asset_deployer' => 'Drupal\scolta_ui\Service\AssetDeployer',
    ];

    foreach ($classesToCheck as $serviceId => $className) {
      $argCount = count($services['services'][$serviceId]['arguments'] ?? []);
      $classFile = $this->classToFile($className);
      $contents = file_get_contents($classFile);

      // Count constructor parameters by looking for the function signature.
      if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $contents, $m)) {
        $params = array_filter(array_map('trim', explode(',', $m[1])));
        $paramCount = count($params);

        $this->assertEquals(
          $paramCount, $argCount,
          "Service '{$serviceId}' has {$argCount} arguments but constructor has {$paramCount} parameters"
        );
      }
    }
  }

  public function testDrushCommandArgumentCountMatchesConstructor(): void {
    $drush = Yaml::parseFile($this->moduleRoot . '/drush.services.yml');
    $args = $drush['services']['scolta.commands']['arguments'] ?? [];
    $file = $this->classToFile('Drupal\scolta\Commands\ScoltaCommands');
    $contents = file_get_contents($file);

    if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $contents, $m)) {
      $params = array_filter(array_map('trim', explode(',', $m[1])));
      $this->assertEquals(count($params), count($args),
        "Drush command argument count mismatch");
    }
  }

  // -------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------

  private function resolveScoltaPhpSrc(): ?string {
    $candidates = [
      $this->moduleRoot . '/../scolta-php/src/',
      $this->moduleRoot . '/vendor/tag1/scolta-php/src/',
    ];
    foreach ($candidates as $path) {
      if (is_dir($path)) {
        return $path;
      }
    }
    return null;
  }

  /**
   * Resolve a class in either shipped module to its file.
   *
   * The package autoloads two namespaces from two directories:
   * Drupal\scolta from src/, Drupal\scolta_ui from modules/scolta_ui/src/.
   * scolta_ui is matched first because Drupal\scolta\ is a prefix of
   * Drupal\scolta_ui\ and would otherwise swallow it, leaving
   * src/Drupal/scolta_ui/... — a path that exists nowhere.
   */
  private function classToFile(string $fqcn): string {
    $fqcn = ltrim($fqcn, '\\');

    $roots = [
      'Drupal\\scolta_ui\\' => '/modules/scolta_ui/src/',
      'Drupal\\scolta\\' => '/src/',
    ];

    foreach ($roots as $namespace => $directory) {
      if (str_starts_with($fqcn, $namespace)) {
        $relative = str_replace('\\', '/', substr($fqcn, strlen($namespace)));
        return $this->moduleRoot . $directory . $relative . '.php';
      }
    }

    $this->fail("{$fqcn} is in neither module's namespace");
  }

  // -------------------------------------------------------------------
  // isExecutable() guard
  // -------------------------------------------------------------------

  public function testCommandsDoNotCallIsExecutable(): void {
    $source = file_get_contents($this->moduleRoot . '/src/Commands/ScoltaCommands.php');
    $this->assertStringNotContainsString(
      'isExecutable()',
      $source,
      'Drush commands must not call private isExecutable(); use resolve() + status() instead'
    );
  }

  // -------------------------------------------------------------------
  // PHPStan configuration
  // -------------------------------------------------------------------

  public function testPhpstanConfigIncludesDeprecationRules(): void {
    $neon = file_get_contents($this->moduleRoot . '/phpstan.neon');
    $this->assertStringContainsString('phpstan-deprecation-rules/rules.neon', $neon,
      'phpstan.neon must include phpstan-deprecation-rules for deprecation detection');
  }

  public function testPhpstanConfigDocumentsMglamanExtension(): void {
    $neon = file_get_contents($this->moduleRoot . '/phpstan.neon');
    $this->assertStringContainsString('mglaman/phpstan-drupal', $neon,
      'phpstan.neon must document the mglaman/phpstan-drupal extension (even if excluded in standalone mode)');
  }

  public function testPhpstanBaselineExists(): void {
    $this->assertFileExists($this->moduleRoot . '/phpstan-baseline.neon',
      'phpstan-baseline.neon must exist to track pre-existing errors');
  }

  // -------------------------------------------------------------------
  // .gitattributes covers dev files
  // -------------------------------------------------------------------

  public function testGitattributesExcludesDevFiles(): void {
    $path = $this->moduleRoot . '/.gitattributes';
    $this->assertFileExists($path,
      '.gitattributes must exist to exclude dev files from distribution archives');
    $content = file_get_contents($path);
    $this->assertStringContainsString('tests/', $content,
      '.gitattributes must exclude /tests/ from distribution');
    $this->assertStringContainsString('export-ignore', $content,
      '.gitattributes must use export-ignore directives');
    $this->assertStringContainsString('.github/', $content,
      '.gitattributes must exclude /.github/ from distribution');
    $this->assertStringContainsString('phpstan.neon', $content,
      '.gitattributes must exclude phpstan.neon from distribution');
  }

  // -------------------------------------------------------------------
  // No raw filesystem calls in src/ (regression guard)
  // -------------------------------------------------------------------

  /**
   * Verify no raw PHP filesystem functions exist in src/ without phpcs:ignore.
   *
   * Method calls via FileSystemInterface (->mkdir, ->delete, etc.) are allowed.
   * Only plain PHP function calls (mkdir(), unlink(), etc.) are flagged.
   *
   * Allowed exceptions:
   * - Any line with a phpcs:ignore comment (including the line above)
   * - proc_open/feof/fgets/fclose/fread (subprocess pipe operations)
   * - Method calls: ->mkdir(, ->delete(, etc.
   */
  public function testNoRawFilesystemCalls(): void {
    // Raw PHP function names to check — only flag bare calls, not method calls.
    $forbidden = [
      'strip_tags(',
      'file_put_contents(',
      'unlink(',
      'rmdir(',
      'mkdir(',
      'copy(',
      'chmod(',
    ];
    // Patterns that make a line acceptable even if it matches a forbidden func.
    $allowed = ['phpcs:ignore', 'proc_open', 'feof', 'fgets', 'fclose', 'fread'];

    $violations = [];
    foreach (array_keys(PackageManifest::sourceFiles()) as $relative) {
      $lines = file($this->moduleRoot . '/' . $relative);
      foreach ($lines as $num => $line) {
        foreach ($forbidden as $func) {
          if (!str_contains($line, $func)) {
            continue;
          }
          // Skip method calls: ->mkdir(, ->copy(, etc.
          $funcName = rtrim($func, '(');
          if (preg_match('/->\\s*' . preg_quote($funcName, '/') . '\\s*\\(/', $line)) {
            continue;
          }
          // Skip static calls: FileSystemInterface::mkdir( etc.
          if (preg_match('/::' . preg_quote($funcName, '/') . '\\s*\\(/', $line)) {
            continue;
          }
          // Check this line and the previous line for phpcs:ignore.
          $context = $line . ($lines[$num - 1] ?? '');
          $isAllowed = FALSE;
          foreach ($allowed as $exception) {
            if (str_contains($context, $exception)) {
              $isAllowed = TRUE;
              break;
            }
          }
          if (!$isAllowed) {
            $violations[] = $relative . ':' . ($num + 1) . ' — ' . trim($line);
          }
        }
      }
    }

    $this->assertEmpty($violations,
      "Raw filesystem calls found (add phpcs:ignore with explanation if intentional):\n" . implode("\n", $violations));
  }

  // -------------------------------------------------------------------
  // The module version comes from scolta.info.yml, not composer.json.
  // -------------------------------------------------------------------

  /**
   * composer.json must not declare a "version".
   *
   * A declared version overrides the one Composer derives from the branch or
   * tag, which is what the extra.branch-alias beside it exists to describe.
   * Packagist ignores the declared string; the drupal.org Composer facade
   * does not, so drupal/scolta presented itself as a fixed "1.0.6-dev"
   * whatever branch it was built from. A consuming site constrained to
   * dev-1.0.x could `composer update` (Composer knows the provenance of what
   * it just fetched) but the resulting lock recorded "1.0.6-dev", and
   * `composer install` compares only that recorded string against the
   * constraint — so it failed on every clean checkout. That broke a client's
   * CI on 2026-07-27 while the site installed fine on developer machines.
   */
  public function testComposerJsonDeclaresNoVersion(): void {
    $composer = json_decode(file_get_contents($this->moduleRoot . '/composer.json'), TRUE);

    $this->assertArrayNotHasKey('version', $composer,
      'composer.json must not declare a version. scolta.info.yml is the source ' .
      'of the module version (drupal.org injects the release version there at ' .
      'packaging time); extra.branch-alias describes the dev-main mapping.');
  }

  /**
   * scolta.info.yml must declare the version, since composer.json no longer
   * does. Removing both would leave the module with no version at all.
   */
  public function testInfoYmlDeclaresTheVersion(): void {
    $info = Yaml::parseFile($this->moduleRoot . '/scolta.info.yml');

    $this->assertArrayHasKey('version', $info,
      'scolta.info.yml is now the only place the module version is declared.');
    $this->assertNotEmpty($info['version']);
  }

  // -------------------------------------------------------------------
  // The browser bundle deploys from vendor; no copy may be committed.
  // -------------------------------------------------------------------

  /**
   * No copy of the scolta-php browser bundle may be committed here.
   *
   * The bundle is canonical in scolta-php's assets/ and is deployed to
   * public://scolta-assets by AssetDeployer, at install time and on every
   * cache rebuild. A committed copy would resurrect the retired bug class:
   * it goes stale the moment scolta-php's bundle changes, it needs a
   * re-vendor commit (and a CI parity gate) to stay honest, and whichever of
   * the two copies actually got served would be an accident of deployment
   * order. The copy-assets composer script goes with it — with nothing
   * committed there is nothing to re-vendor.
   */
  public function testNoBrowserBundleFilesAreCommitted(): void {
    $bundle = [
      'js/scolta.js',
      'css/scolta.css',
      'js/wasm/scolta_core.js',
      'js/wasm/scolta_core_bg.wasm',
    ];
    foreach ($bundle as $path) {
      $this->assertFileDoesNotExist($this->moduleRoot . '/' . $path,
        "{$path} must not be committed: the bundle deploys from the installed " .
        'tag1/scolta-php via AssetDeployer, and a committed copy goes stale.');
    }

    $composer = json_decode(file_get_contents($this->moduleRoot . '/composer.json'), TRUE);
    $this->assertArrayNotHasKey('copy-assets', $composer['scripts'] ?? [],
      'composer.json must not carry a copy-assets script: nothing is committed, so there is nothing to re-vendor.');
  }

  /**
   * The search library must serve the deployed bundle, and keep it fresh.
   *
   * Four parts, each load-bearing. The library must reference
   * public://scolta-assets, because vendor/ is not web-accessible and the
   * module directory is read-only on immutable-code hosts. Those URIs must
   * then be resolved by scolta_ui_library_info_alter() before the library is
   * built, because a colon in a JS path is fatal once locale is enabled —
   * declaring them unresolved returned HTTP 500 on every rendered page of a
   * multilingual site. scolta_ui.module must implement hook_rebuild(), because
   * that is what makes `composer update` + `drush cr` sufficient to pick up
   * a new bundle — hook_install() runs once per site ever, so without the
   * rebuild hook an updating site would serve the old bundle indefinitely,
   * which is the same staleness the committed copies had. And the install
   * hook must deploy too, so a fresh install serves assets before its first
   * rebuild.
   *
   * @see \Drupal\Tests\scolta\Functional\LocaleAssetPathFunctionalTest
   */
  public function testSearchLibraryServesDeployedAssets(): void {
    $libraries = PackageManifest::libraries();
    $searchJs = array_keys($libraries['search']['js'] ?? []);
    $searchCss = array_keys($libraries['search']['css']['theme'] ?? []);
    $this->assertSame(['public://scolta-assets/js/scolta.js'], $searchJs,
      'The search library JS must be the deployed public://scolta-assets copy.');
    $this->assertSame(['public://scolta-assets/css/scolta.css'], $searchCss,
      'The search library CSS must be the deployed public://scolta-assets copy.');

    // The bundle is scolta_ui's to deploy: it is the module that declares the
    // libraries above and renders the search that loads them, and a site that
    // only builds an index has no browser to hand them to.
    $module = file_get_contents($this->moduleRoot . '/modules/scolta_ui/scolta_ui.module');
    $this->assertStringContainsString('function scolta_ui_rebuild()', $module,
      'scolta_ui.module must implement hook_rebuild() to redeploy the bundle on cache rebuild.');
    $this->assertStringContainsString('function scolta_ui_library_info_alter(', $module,
      'scolta_ui.module must implement hook_library_info_alter(): the public:// URIs above are fatal on a site with locale enabled until it resolves them to a local path.');

    $install = file_get_contents($this->moduleRoot . '/modules/scolta_ui/scolta_ui.install');
    $this->assertStringContainsString("service('scolta.asset_deployer')->deploy()", $install,
      'scolta_ui_install() must deploy the bundle so a fresh install serves assets immediately.');
  }

  // -------------------------------------------------------------------
  // Namespace-less files: the gap phpstan and phpcs both leave open.
  // -------------------------------------------------------------------

  /**
   * Every class a .module or .install file names must be imported.
   *
   * These four files have no namespace, so an unimported `Foo::BAR` resolves
   * to `\Foo` and throws "Class Foo not found" the moment the line runs. It
   * is a white screen, not a warning, and nothing else in the toolchain sees
   * it: phpcs does not resolve class names and phpstan is configured for
   * `.php` files only, so both stay green. The backend/frontend split lost
   * exactly this import — AssetDeployer, used by the library alter, was left
   * behind in scolta.module — and every rendered page of every site with the
   * frontend installed would have died on the first cache rebuild.
   *
   * References are found by tokenizing rather than by regex so a class name
   * inside a comment or a docblock cannot produce a false failure.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('proceduralFileProvider')]
  public function testProceduralFilesImportEveryClassTheyName(string $relative, string $source): void {
    $imported = $this->importedShortNames($source);
    $referenced = $this->unqualifiedClassReferences($source);

    foreach ($referenced as $name) {
      // A class that really does live in the global namespace (Exception,
      // ArrayObject, …) needs no import and must not be reported.
      if (class_exists($name, FALSE) || interface_exists($name, FALSE)) {
        continue;
      }
      $this->assertContains(
        $name,
        $imported,
        "{$relative} names {$name} with no namespace and no use statement: it will resolve to \\{$name} and fatal at runtime"
      );
    }
  }

  public static function proceduralFileProvider(): \Generator {
    foreach (PackageManifest::proceduralFiles() as $relative => $source) {
      yield $relative => [$relative, $source];
    }
  }

  /**
   * The short names a file imports, including aliases.
   *
   * @return string[]
   *   Short class names.
   */
  private function importedShortNames(string $source): array {
    preg_match_all('/^use\s+([^;]+);/m', $source, $matches);

    $names = [];
    foreach ($matches[1] as $statement) {
      $statement = trim($statement);
      if (preg_match('/\s+as\s+(\w+)$/i', $statement, $alias)) {
        $names[] = $alias[1];
        continue;
      }
      $parts = explode('\\', $statement);
      $names[] = end($parts);
    }

    return $names;
  }

  /**
   * Unqualified class names a file references, from its token stream.
   *
   * Only the three forms that name a class without importing it can be
   * written unqualified and still be wrong: `Foo::`, `new Foo`, and
   * `instanceof Foo`. A qualified name (`Drupal\Core\Url`) or a fully
   * qualified one (`\Drupal`) tokenizes as a single name token and never
   * reaches here, which is what makes `\Drupal::service()` — the normal
   * idiom in these files — correctly invisible to this sweep.
   *
   * @return string[]
   *   Distinct short class names.
   */
  private function unqualifiedClassReferences(string $source): array {
    $tokens = token_get_all($source);
    $names = [];

    foreach ($tokens as $index => $token) {
      if (!is_array($token) || $token[0] !== T_STRING) {
        continue;
      }

      // Foo::CONSTANT / Foo::method().
      $next = $this->nextMeaningfulToken($tokens, $index);
      if ($next !== NULL && is_array($next) && $next[0] === T_DOUBLE_COLON) {
        $names[$token[1]] = TRUE;
        continue;
      }

      // new Foo(...) / $x instanceof Foo.
      $previous = $this->previousMeaningfulToken($tokens, $index);
      if ($previous !== NULL && is_array($previous) && in_array($previous[0], [T_NEW, T_INSTANCEOF], TRUE)) {
        $names[$token[1]] = TRUE;
      }
    }

    unset($names['self'], $names['static'], $names['parent'], $names['class']);

    return array_keys($names);
  }

  /**
   * The next token that is not whitespace or a comment.
   */
  private function nextMeaningfulToken(array $tokens, int $index): array|string|null {
    for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
      if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)) {
        continue;
      }
      return $tokens[$i];
    }
    return NULL;
  }

  /**
   * The previous token that is not whitespace or a comment.
   */
  private function previousMeaningfulToken(array $tokens, int $index): array|string|null {
    for ($i = $index - 1; $i >= 0; $i--) {
      if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)) {
        continue;
      }
      return $tokens[$i];
    }
    return NULL;
  }

  // -------------------------------------------------------------------
  // Neither module may name the other's routes.
  // -------------------------------------------------------------------

  /**
   * Every route a module names in code must be one it defines itself.
   *
   * The split's central promise is that either module runs alone, and a
   * Url::fromRoute() naming the other module's route breaks it in the way
   * that is hardest to notice: the call throws RouteNotFoundException, so
   * the page 500s, but only on the install combination nobody runs locally.
   * DismissRebuildNoticeController redirected to scolta.settings — the
   * frontend's — so on an index-builder install dismissing the rebuild
   * notice returned a 500.
   *
   * Core's own routes are exempt: those exist wherever Drupal does.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('moduleSourceProvider')]
  public function testAModuleNamesOnlyItsOwnRoutes(string $module, array $files, array $ownRoutes): void {
    foreach ($files as $relative => $source) {
      preg_match_all("/fromRoute\(\s*'([a-z0-9_.]+)'/i", $source, $matches);

      foreach (array_unique($matches[1]) as $routeName) {
        // Only the package's own namespace is in scope; core and contrib
        // routes are defined wherever Drupal is.
        if (!str_starts_with($routeName, 'scolta')) {
          continue;
        }
        $this->assertContains(
          $routeName,
          $ownRoutes,
          "{$relative} builds a URL for '{$routeName}', which {$module} does not define: on an install without the other module Url::fromRoute() throws and the request 500s"
        );
      }
    }
  }

  public static function moduleSourceProvider(): array {
    $root = PackageManifest::root();
    $manifests = PackageManifest::each('routing');

    $owned = [
      'scolta' => ['src/', 'scolta.module', 'scolta.install'],
      'scolta_ui' => ['modules/scolta_ui/'],
    ];

    $cases = [];
    foreach ($owned as $module => $prefixes) {
      $files = [];
      $all = PackageManifest::sourceFiles() + PackageManifest::proceduralFiles();
      foreach ($all as $relative => $source) {
        foreach ($prefixes as $prefix) {
          if (str_starts_with($relative, $prefix)) {
            $files[$relative] = $source;
            break;
          }
        }
      }
      $cases[$module] = [$module, $files, array_keys($manifests[$module] ?? [])];
    }

    return $cases;
  }

  // -------------------------------------------------------------------
  // Distribution archive manifest
  // -------------------------------------------------------------------

  /**
   * Every entry that ships at the top level must be change-controlled.
   *
   * scripts/validate-dist-archive.sh fails closed on a top-level entry it
   * does not recognise, which is the right posture — but it runs in its own
   * CI job, and a branch whose full suite has not been fired yet never asks
   * it anything. Splitting the package into scolta and scolta_ui added a
   * top-level modules/ directory that the allowlist did not name, so the
   * release that exists to ship the frontend would have been refused by the
   * gate guarding it. Ask the same question here, where the suite runs.
   */
  public function testEveryTopLevelEntryIsEitherShippedOrExportIgnored(): void {
    $allowed = $this->distArchiveList('ALLOWED_TOP_LEVEL');
    $excluded = $this->distArchiveList('EXCLUDED_PATHS');
    $known = array_merge($allowed, $excluded);

    foreach ($this->trackedTopLevelEntries() as $entry) {
      $this->assertContains(
        $entry,
        $known,
        "Top-level entry '{$entry}' is neither on ALLOWED_TOP_LEVEL nor on "
        . 'EXCLUDED_PATHS in scripts/validate-dist-archive.sh, so the dist '
        . 'archive gate will refuse the release. Ship it or export-ignore it.'
      );
    }
  }

  /**
   * Nothing on the allowlist may name a path that no longer exists.
   *
   * The allowlist is a permit list, so a stale entry cannot fail the script —
   * it just quietly permits nothing. That is how scolta.libraries.yml and js/
   * stayed on it after both moved into scolta_ui: the list stopped describing
   * the archive and nobody heard about it.
   */
  public function testTheAllowlistDescribesTheArchiveItGuards(): void {
    foreach ($this->distArchiveList('ALLOWED_TOP_LEVEL') as $entry) {
      $this->assertFileExists(
        $this->moduleRoot . '/' . $entry,
        "ALLOWED_TOP_LEVEL names '{$entry}', which is not in the tree any more"
      );
    }
  }

  /**
   * Every runtime path the gate requires must actually be there.
   *
   * REQUIRED_PATHS is the list that catches an over-broad export-ignore
   * shipping a dead module. It named the backend's files only; after the
   * split, a filter that dropped modules/ entirely would have produced a
   * tarball that installs scolta and cannot install scolta_ui, and every
   * required path would still have been present.
   */
  public function testEveryRequiredRuntimePathIsPresent(): void {
    $required = $this->distArchiveList('REQUIRED_PATHS');
    $this->assertContains(
      'modules/scolta_ui/scolta_ui.info.yml',
      $required,
      'The frontend module ships from this package; its manifest must be a required path'
    );

    foreach ($required as $path) {
      $this->assertFileExists(
        $this->moduleRoot . '/' . $path,
        "scripts/validate-dist-archive.sh requires '{$path}' in the archive, "
        . 'but it is not in the tree'
      );
    }
  }

  /**
   * Read one bash array out of the dist-archive validator.
   *
   * @return string[]
   *   The array's entries, comments and quoting stripped.
   */
  private function distArchiveList(string $name): array {
    $script = file_get_contents($this->moduleRoot . '/scripts/validate-dist-archive.sh');
    $this->assertIsString($script, 'scripts/validate-dist-archive.sh must be readable');

    $matched = preg_match('/^' . preg_quote($name, '/') . '=\((.*?)^\)/ms', $script, $m);
    $this->assertSame(1, $matched, "scripts/validate-dist-archive.sh must define {$name}");

    $entries = [];
    foreach (explode("\n", $m[1]) as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      $entries[] = trim($line, '"\'');
    }
    $this->assertNotEmpty($entries, "{$name} must not be empty");

    return $entries;
  }

  /**
   * Top-level entries that git tracks, and so that git archive will emit.
   *
   * Derived from .gitignore rather than from a hardcoded skip list, so a new
   * build directory does not have to be taught to this test twice.
   *
   * @return string[]
   *   Top-level file and directory names.
   */
  private function trackedTopLevelEntries(): array {
    $ignored = ['.', '..', '.git'];
    foreach (explode("\n", file_get_contents($this->moduleRoot . '/.gitignore')) as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      $ignored[] = explode('/', trim($line, '/'))[0];
    }

    $entries = array_diff(scandir($this->moduleRoot), $ignored);

    return array_values($entries);
  }

  // -------------------------------------------------------------------
  // Release workflow constraint guard
  // -------------------------------------------------------------------

  /**
   * The release must be gated on scolta-php existing as a published release.
   *
   * This used to read the committed composer.lock and refuse a lock naming a
   * development version. No lock is committed here — this is a library, not a
   * deployed application — so the same question is asked of the manifest and
   * of Packagist: the declared floor must not be a development constraint,
   * and a published stable release must satisfy it. Without this job the
   * coordination is prose again, and scolta-drupal could be tagged against a
   * scolta-php that nobody can install.
   */
  public function test_release_workflow_has_constraint_guard(): void {
    $workflow = file_get_contents($this->moduleRoot . '/.github/workflows/release.yml');
    $this->assertStringContainsString(
      'CONSTRAINT GUARD FAILED',
      $workflow,
      'Release workflow must gate on the tag1/scolta-php constraint naming a published release'
    );
    $this->assertStringContainsString(
      'repo.packagist.org/p2/tag1/scolta-php.json',
      $workflow,
      'The constraint guard must check the constraint against published releases, not just its syntax'
    );
    $this->assertStringNotContainsString(
      'LOCK GUARD FAILED',
      $workflow,
      'No composer.lock is committed here; the release gate must not read one'
    );
  }

  /**
   * The release is notes-only. This module is distributed via drupal.org (the
   * packager builds the tarball from git.drupalcode.org), so a GitHub
   * vendor-bundled release asset has no consumer. Guard against silently
   * re-adding a custom build artifact or validate-zip job.
   */
  public function testReleaseWorkflowUploadsNoBuildArtifact(): void {
    $workflow = file_get_contents($this->moduleRoot . '/.github/workflows/release.yml');
    $this->assertStringNotContainsString(
      'scolta-drupal-',
      $workflow,
      'Release workflow must not build or upload a scolta-drupal-*.zip asset'
    );
    $this->assertStringNotContainsString(
      'validate-zip',
      $workflow,
      'Release workflow must not include a validate-zip job (no release asset to validate)'
    );
    $this->assertStringNotContainsString(
      'files:',
      $workflow,
      'Release workflow must be notes-only (no files: upload to the release)'
    );
  }

}
