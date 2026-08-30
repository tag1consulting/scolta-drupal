<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Tests\BrowserTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\scolta\Service\AssetDeployer;

/**
 * Proves a multilingual site can render pages with the search library on.
 *
 * The bug this guards: the deployed bundle was declared in
 * scolta.libraries.yml as public://scolta-assets/js/scolta.js, and locale's
 * hook_js_alter() hands every file asset on the page to
 * _locale_parse_js_file(), which throws on any path containing a colon. So
 * with the locale module enabled every rendered page returned HTTP 500 —
 * the front page included — while the /api/scolta/v1 routes kept returning
 * 200, because a JSON response never runs the JS translation scan. A fleet
 * run caught it only because one demo out of four happened to be
 * multilingual, and nothing in this repository caught it at all.
 *
 * The guard needs the whole render pipeline: the failure is in asset
 * resolution during a page render, so a kernel test that never renders a
 * page would not see it. It also needs the library actually attached, hence
 * the placed search block — a page without it never resolves scolta/search
 * and renders fine no matter what the library says.
 *
 * Enabling locale is by itself enough to reproduce: locale's own
 * hook_library_info_alter() adds locale/translations as a dependency of
 * core/drupal unconditionally, so the placeholder that triggers the scan is
 * on every page regardless of how many languages exist. A second language
 * is added anyway, because that is the configuration real sites are in and
 * it keeps the guard honest if core ever makes the scan conditional.
 *
 * @group scolta
 */
class LocaleAssetPathFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'scolta',
    'search_api',
    'node',
    'block',
    'language',
    'locale',
    'dblog',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    ConfigurableLanguage::createFromLangcode('de')->save();

    // ScoltaSearchBlock::build() returns early and attaches nothing at all
    // when no index exists, so without this fixture the block renders empty,
    // scolta/search is never attached, and every assertion below passes
    // against a page that was never at risk. The fake index is the whole
    // reason this test can fail.
    $outputUri = \Drupal::config('scolta.settings')->get('pagefind.output_dir') ?? 'public://scolta-pagefind';
    $realDir = \Drupal::service('stream_wrapper_manager')->getViaUri($outputUri)->realpath();
    $this->assertNotFalse($realDir, 'The Pagefind output directory must resolve to a local path.');
    @mkdir($realDir . '/pagefind', 0777, TRUE);
    file_put_contents($realDir . '/pagefind/pagefind.js', '// fake index');
    file_put_contents($realDir . '/pagefind/pagefind-entry.json', '{}');

    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    $this->drupalPlaceBlock('scolta_search', ['region' => 'content']);
  }

  /**
   * A page carrying the search block renders, with no locale exception.
   *
   * Also checks the front page: it is where the outage was visible (a site
   * whose every page 500s is down, not degraded), and the underlying bug —
   * locale's own hook_library_info_alter() adding locale/translations to
   * every page unconditionally — reproduces there independent of whether the
   * search block is attached.
   */
  public function testSearchPageRendersWithLocaleEnabled(): void {
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Search',
    ]);

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('scolta', $this->getDrupalSettings(),
      'The page must actually render the search block — a page that does not attach scolta/search cannot fail this test, so it would prove nothing.');
    $this->assertSession()->responseContains('scolta.js');
    $this->assertNoLocaleParseException('a page rendering the search block');

    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertNoLocaleParseException('front page');
  }

  /**
   * The resolved library must name a colon-free path to the deployed file.
   *
   * The behavioral assertions above are what matter, but they say nothing
   * about *why* a page rendered. This pins the mechanism: whatever the YAML
   * declares, the library the asset system sees must carry a path locale
   * accepts, it must resolve to the file AssetDeployer actually deployed,
   * and it must still be a 'file' asset — an 'external' one would slip past
   * locale while silently losing aggregation and cache busting.
   */
  public function testResolvedLibraryPathIsLocalAndDeployed(): void {
    $library = \Drupal::service('library.discovery')
      ->getLibraryByName('scolta', 'search');
    $this->assertNotFalse($library, 'The scolta/search library must exist.');

    $assets = array_merge($library['js'], $library['css']);
    $this->assertCount(2, $assets, 'The search library must declare one JS and one CSS file.');

    foreach ($assets as $asset) {
      $this->assertSame('file', $asset['type'],
        'The deployed bundle must stay a file asset: external assets are not aggregated and get no cache-busting query string.');
      $this->assertStringNotContainsString(':', $asset['data'],
        'A colon in the path is what _locale_parse_js_file() rejects: ' . $asset['data']);
      $this->assertFileExists($this->root . '/' . $asset['data'],
        'The resolved path must point at the file AssetDeployer deployed: ' . $asset['data']);
    }

    // The resolved path must be derived from the stream wrapper, not
    // hardcoded — a site that relocates its public files directory has to be
    // followed. Comparing against the deployer's own resolution of the same
    // URI is the assertion that a hardcoded sites/default/files would fail
    // on a relocated site.
    /** @var \Drupal\scolta\Service\AssetDeployer $deployer */
    $deployer = \Drupal::service('scolta.asset_deployer');
    $expected = ltrim((string) $deployer->webPath(AssetDeployer::DIRECTORY . '/js/scolta.js'), '/');
    $this->assertSame($expected, $library['js'][0]['data'],
      'The library JS path must be the wrapper-resolved path to the deployed bundle.');
  }

  /**
   * Fail if any page render logged the locale colon rejection.
   *
   * Asserted separately from the status code because the two failures are
   * different: a 500 says the page broke, this says it broke *here*. A
   * future regression that swallowed the exception and served a page
   * without its search assets would pass the status check and fail this.
   */
  protected function assertNoLocaleParseException(string $context): void {
    $rows = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['type', 'message', 'variables'])
      ->condition('w.severity', RfcLogLevel::ERROR, '<=')
      ->execute()
      ->fetchAll();

    foreach ($rows as $row) {
      $haystack = $row->message . ' ' . $row->variables;
      $this->assertStringNotContainsString(
        '_locale_parse_js_file',
        $haystack,
        "Rendering {$context} threw the locale colon rejection: a Scolta asset is declared by a path locale cannot read. Logged under type '{$row->type}'."
      );
    }
  }

}
