<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Stay-in-sync guard between what the browser reads and what this module emits.
 *
 * The browser bundle is the installed scolta-php's assets/js/scolta.js,
 * deployed to the public files directory by AssetDeployer and read here from
 * vendor — the same bytes a site serves.
 * Every config value it consumes is read off the instance config object that
 * ScoltaSearchBlock::build() puts into drupalSettings, so the two are a
 * contract: a key the bundle reads but no config layer emits is a feature that
 * is dead on arrival, and a key this module emits but the bundle never reads is
 * dead weight in every page payload. Several such gaps accumulated silently on
 * green CI across the Scolta packages for exactly that reason: nothing asserted
 * that the emitted config covered what the browser reads.
 *
 * This test parses the committed bundle for the keys it reads and diffs them
 * against the drupalSettings the module actually renders, in both directions,
 * recursing one level into the `scoring` and `endpoints` sub-objects. Asserting
 * only the top level is not enough: those two are objects, so a top-level
 * presence check passes while a scoring sub-key is missing, which is how three
 * scoring keys hid in scolta-php.
 *
 * It has to be functional rather than unit. The settings array is built inside
 * build(), so only a rendered page gives the real emitted config; and this
 * module has no JS test runner (only Playwright specs), so the guard must be
 * PHP.
 *
 * Two deliberate design choices, shared with the other four implementations:
 *
 * - Comments are NOT stripped before matching. Naively cutting `//` to end of
 *   line would corrupt every line containing a URL such as `https://` and could
 *   silently drop a real key. Today exactly one comment names a config key
 *   (`instanceConfig.currentLanguage`) and that key is real, so comment noise
 *   produces zero phantoms. If a future comment does introduce a phantom, this
 *   test fails loudly and the maintainer either emits the key or adds it to an
 *   allowlist with a written justification. Loud and occasionally wrong beats
 *   silent and blind.
 * - The reverse assertion uses strict set membership against the extracted key
 *   set, not a substring search of the bundle. A substring search over 3,300
 *   lines matches almost any plausible camelCase name and would make the
 *   assertion worthless.
 *
 * The parse is deliberately strict: the tripwire assertions run BEFORE any diff,
 * so a reformat of scolta.js that stops the extraction matching fails loudly
 * instead of passing while asserting nothing.
 *
 * @group scolta
 */
class BrowserConfigParityFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'node', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Keys scolta.js reads that the block deliberately does not emit.
   *
   * Subtracts from the extracted set, so it may only ever contain keys the
   * bundle actually reads.
   */
  private const FORWARD_ALLOWLIST = [
    // Emitted by no adapter at all; supplied only by a direct caller through
    // the createInstance() public API. Note the snake_case name, unlike every
    // other top-level key.
    'priority_pages',
  ];

  /**
   * Keys the block emits that scolta.js does not read off the instance config.
   *
   * Subtracts from the emitted set, so it may only ever contain keys this
   * module actually emits.
   */
  private const REVERSE_ALLOWLIST = [
    // Read only by autoInit() off the global window.scolta, never off the
    // instance config, so it is correctly absent from the extracted set and
    // belongs in no forward allowlist.
    'container',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // ScoltaSearchBlock::build() returns early and attaches NO drupalSettings
    // at all when IndexLocator::exists() is FALSE, so without this fixture the
    // test would assert against an empty block and fail confusingly.
    $outputUri = \Drupal::config('scolta.settings')->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $realDir = \Drupal::service('stream_wrapper_manager')->getViaUri($outputUri)->realpath();
    if ($realDir !== FALSE) {
      @mkdir($realDir . '/pagefind', 0777, TRUE);
      file_put_contents($realDir . '/pagefind/pagefind.js', '// fake index');
      file_put_contents($realDir . '/pagefind/pagefind-entry.json', '{}');
    }
  }

  /**
   * Every top-level key the browser reads must be emitted, and none be dead.
   *
   * Both directions live in one test method here because each needs a rendered
   * page, and rendering twice would double an already slow functional test. The
   * two allowlists stay separate so they can still be reasoned about
   * independently.
   */
  public function testEmittedBrowserConfigMatchesWhatScoltaJsReads(): void {
    $emitted = $this->renderAndExtractDrupalSettings();
    $source = $this->bundleSource();

    // Forward: everything the browser reads must be emitted.
    $read = $this->extractTopLevelKeys($source);
    foreach (array_diff($read, self::FORWARD_ALLOWLIST) as $key) {
      $this->assertArrayHasKey(
        $key,
        $emitted,
        sprintf(
          'scolta.js reads instanceConfig.%s but ScoltaSearchBlock::build() does not emit it, '
          . 'so the feature behind it is unreachable. Either emit the key or add it to %s'
          . '::FORWARD_ALLOWLIST with a written justification.',
          $key,
          __CLASS__
        )
      );
    }

    // Reverse: nothing emitted should be dead weight.
    foreach (array_diff(array_keys($emitted), self::REVERSE_ALLOWLIST) as $key) {
      $this->assertContains(
        $key,
        $read,
        sprintf(
          'ScoltaSearchBlock::build() emits %s but scolta.js never reads it off the instance '
          . 'config, so it is dead weight in every page payload. Either drop it or add it to %s'
          . '::REVERSE_ALLOWLIST with a written justification.',
          $key,
          __CLASS__
        )
      );
    }
  }

  /**
   * Every scoring and endpoint sub-key the browser reads must be emitted.
   */
  public function testEmittedScoringAndEndpointsMatchWhatScoltaJsReads(): void {
    $emitted = $this->renderAndExtractDrupalSettings();
    $source = $this->bundleSource();

    $this->assertArrayHasKey('scoring', $emitted, 'No scoring array was emitted at all.');
    foreach ($this->extractScoringKeys($source) as $key) {
      $this->assertArrayHasKey(
        $key,
        $emitted['scoring'],
        sprintf(
          'scolta.js reads scoring key %s but it is absent from the emitted scoring array, so '
          . 'it can only ever take its hardcoded JS fallback. Add it to the config schema, the '
          . 'install defaults, and the settings form.',
          $key
        )
      );
    }

    $this->assertArrayHasKey('endpoints', $emitted, 'No endpoints array was emitted at all.');
    foreach ($this->extractEndpointKeys($source) as $key) {
      $this->assertArrayHasKey(
        $key,
        $emitted['endpoints'],
        sprintf('scolta.js reads endpoint %s but it is absent from the emitted endpoints array.', $key)
      );
    }
  }

  /**
   * Renders a node page carrying the search block and returns drupalSettings.
   *
   * @return array
   *   The `scolta` key of the page's drupalSettings JSON.
   */
  private function renderAndExtractDrupalSettings(): array {
    $this->drupalCreateContentType(['type' => 'page']);
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Search',
      'status' => 1,
    ]);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    $settings = $this->getDrupalSettings();
    $this->assertArrayHasKey(
      'scolta',
      $settings,
      'The rendered page carries no drupalSettings.scolta. ScoltaSearchBlock::build() attaches '
      . 'nothing when the Pagefind index is missing — check the fixture in setUp().'
    );

    return $settings['scolta'];
  }

  /**
   * The installed scolta-php browser bundle as text.
   */
  private function bundleSource(): string {
    $path = \Composer\InstalledVersions::getInstallPath('tag1/scolta-php') . '/assets/js/scolta.js';
    $source = file_get_contents($path);
    $this->assertNotFalse($source, "Unable to read the scolta-php bundle at {$path}");

    return $source;
  }

  /**
   * Distinct top-level keys read as `instanceConfig.<key>`.
   */
  private function extractTopLevelKeys(string $source): array {
    preg_match_all('/instanceConfig\.([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches);
    $keys = array_values(array_unique($matches[1]));

    $this->assertGreaterThanOrEqual(
      11,
      count($keys),
      'Parsed too few top-level config reads from js/scolta.js — the bundle may have been '
      . 'reformatted so `instanceConfig.<key>` no longer matches. Update the parser in '
      . __CLASS__ . ' so the guard keeps working.'
    );

    return $keys;
  }

  /**
   * Distinct scoring keys read as `KEY: s.KEY ??` in the config return literals.
   *
   * The regex matches two return literals, the module-level getConfig() block
   * and the getInstanceConfig() block, and their union is the full set only
   * because the former's keys are a strict subset of the latter's. That holds
   * today; if it ever stops holding, the tripwire count below moves and whoever
   * hits it reads this note.
   *
   * Parsing the literals rather than grepping consumption sites is deliberate:
   * several keys are forwarded to WASM wholesale and never named at a use site,
   * so a consumption-site grep would silently miss them.
   */
  private function extractScoringKeys(string $source): array {
    preg_match_all('/^\s*([A-Z][A-Z0-9_]*):\s*s\.\1\s*\?\?/m', $source, $matches);
    $keys = array_values(array_unique($matches[1]));

    $this->assertGreaterThanOrEqual(
      40,
      count($keys),
      'Parsed too few scoring keys from js/scolta.js — the getInstanceConfig() return literal '
      . 'may have been reformatted so `KEY: s.KEY ??` no longer matches. Update the parser in '
      . __CLASS__ . ' so the guard keeps working.'
    );

    return $keys;
  }

  /**
   * Distinct endpoint keys read as `key: e.key ||`.
   */
  private function extractEndpointKeys(string $source): array {
    preg_match_all('/^\s*([a-z]+):\s*e\.\1\s*\|\|/m', $source, $matches);
    $keys = array_values(array_unique($matches[1]));

    $this->assertCount(
      3,
      $keys,
      'Expected exactly 3 endpoint keys in js/scolta.js (expand, summarize, followup) but '
      . 'parsed ' . count($keys) . '. Either an endpoint was added or the bundle was '
      . 'reformatted so `key: e.key ||` no longer matches. Update the parser in '
      . __CLASS__ . ' so the guard keeps working.'
    );

    return $keys;
  }

}
