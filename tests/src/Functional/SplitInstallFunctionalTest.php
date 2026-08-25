<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Stands up all three install combinations and exercises each one.
 *
 * This is #168's structural requirement made into a running site rather than
 * an audit. Either module must work alone, which means neither may reference
 * a service, route, permission or config object the other defines — and a
 * dangling reference of that kind does not show up in a code read. It shows
 * up as a container that will not compile, or a 500 on a route, on a site
 * nobody built until now.
 *
 * Three combinations, one class each because $modules is static per class:
 *
 * - Backend alone: the index builder. Builds and serves an index, renders no
 *   search of its own.
 * - Frontend alone: the thin consumer. Renders search and answers AI with no
 *   build step, no search_api, and no access to anyone's content source.
 * - Both: the default install, which must behave exactly as the single
 *   module did.
 *
 * @group scolta
 */
class SplitInstallFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'scolta_ui', 'search_api', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The default install is the pre-split behaviour, unchanged.
   */
  public function testBothModulesGiveTheWholeSurface(): void {
    $admin = $this->drupalCreateUser(['administer scolta', 'administer scolta ui']);
    $this->drupalLogin($admin);

    $this->drupalGet('admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('admin/config/search/scolta/index');
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('api/scolta/v1/health');
    $this->assertSession()->statusCodeEquals(200);

    // Both halves of the settings object exist and neither holds the other's
    // keys — the property scolta_update_10006() gives an upgrading site, here
    // asserted for a fresh one.
    $backend = $this->config('scolta.settings');
    $frontend = $this->config('scolta_ui.settings');

    $this->assertNotNull($backend->get('pagefind.output_dir'));
    $this->assertNull($backend->get('scoring'), 'scolta.settings must not hold query-time keys');

    $this->assertNotNull($frontend->get('scoring'));
    $this->assertNull($frontend->get('pagefind'), 'scolta_ui.settings must not hold build-time keys');

    // Both pointers ship at this site, so nothing about the default install
    // reaches across the network.
    $this->assertSame('<local>', $frontend->get('index_origin'));
    $this->assertSame('<local>', $frontend->get('ai_origin'));

    // Both services resolve, in one container.
    $this->assertNotNull(\Drupal::service('scolta.index_locator'));
    $this->assertNotNull(\Drupal::service('scolta_ui.index_origin'));
    $this->assertNotNull(\Drupal::service('scolta_ui.ai_origin'));
  }

}
