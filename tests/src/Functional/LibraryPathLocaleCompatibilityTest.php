<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;

/**
 * Proves the deployed bundle's asset paths survive locale's JS scanner.
 *
 * This asserts the *path*, not a rendered page. A companion test that placed
 * the block and fetched a page was written first and dropped: locale's
 * translation-placeholder library is not attached under BrowserTestBase, so
 * the page rendered fine with the fix reverted and the test proved nothing.
 * The path assertion does fail when the alter is removed, which is verified.
 *
 * scolta.libraries.yml references the deployed bundle by stream-wrapper URI,
 * which library definitions support and which serves correctly. What it does
 * not survive is locale's hook_js_alter(), which feeds every `type: file`
 * asset to _locale_parse_js_file() — a function that rejects any path
 * containing a colon. With locale installed and the search block placed, that
 * threw on every page of the site, so this is a WSOD regression test, not an
 * asset-path style preference.
 *
 * A monolingual site never parses JS for translatable strings, which is why
 * every existing test passed while the bug shipped. `locale` is therefore in
 * the module list here on purpose: without it this test proves nothing.
 *
 * @group scolta
 */
class LibraryPathLocaleCompatibilityTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api', 'locale', 'language'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // A second language is what arms locale's hook_js_alter(): with English as
    // the interface language there is no translation placeholder in the JS
    // array, nothing gets parsed, and the bug is invisible — which is exactly
    // how it reached a release.
    ConfigurableLanguage::createFromLangcode('es')->save();
  }

  /**
   * No resolved asset path may contain a colon.
   */
  public function testResolvedAssetPathsCarryNoStreamWrapperScheme(): void {
    $library = \Drupal::service('library.discovery')->getLibraryByName('scolta', 'search');

    $this->assertNotEmpty($library['js'], 'The search library must declare JS.');

    // JS only: locale parses nothing else, so only JS is rewritten.
    foreach (['js'] as $type) {
      foreach ($library[$type] as $asset) {
        if (($asset['type'] ?? 'file') !== 'file') {
          continue;
        }
        $this->assertStringNotContainsString(
          ':',
          $asset['data'],
          sprintf(
            'Resolved %s path "%s" still carries a scheme; _locale_parse_js_file() throws on any path containing a colon.',
            $type,
            $asset['data']
          )
        );
        $this->assertStringContainsString(
          'scolta-assets/',
          $asset['data'],
          'The rewritten path must still point into the deployed bundle directory.'
        );
      }
    }
  }

}
