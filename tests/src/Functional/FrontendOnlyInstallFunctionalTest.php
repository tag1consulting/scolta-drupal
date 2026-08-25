<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;

/**
 * A thin consumer: scolta_ui installed, scolta and search_api not.
 *
 * The direct answer to "how do we know a frontend-only install has no
 * dangling reference": one is actually stood up and exercised. Any surviving
 * reference to a backend-only service, route or permission fails container
 * compilation or returns a 500, and this catches it rather than a reviewer
 * having to.
 *
 * @group scolta
 */
class FrontendOnlyInstallFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta_ui', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The container compiles without the backend or search_api.
   */
  public function testContainerCompilesWithoutTheBackend(): void {
    $this->assertFalse(
      \Drupal::moduleHandler()->moduleExists('scolta'),
      'This test is only meaningful with the backend absent'
    );
    $this->assertFalse(
      \Drupal::moduleHandler()->moduleExists('search_api'),
      'The frontend must not drag search_api in'
    );

    $this->assertNotNull(\Drupal::service('scolta.ai_service'));
    $this->assertNotNull(\Drupal::service('scolta_ui.index_origin'));
    $this->assertNotNull(\Drupal::service('scolta_ui.ai_origin'));
    $this->assertNotNull(\Drupal::service('scolta.asset_deployer'));

    $this->assertFalse(\Drupal::hasService('scolta.index_locator'));
    $this->assertFalse(\Drupal::hasService('scolta.content_gatherer'));
  }

  /**
   * The settings screen serves 200, behind this module's own permission.
   */
  public function testSettingsRouteServes(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer scolta ui']));

    $this->drupalGet('admin/config/search/scolta');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Index and AI origin');
  }

  /**
   * The three AI endpoints answer rather than 500 or 404.
   */
  public function testAiEndpointsRespond(): void {
    $this->drupalLogin($this->drupalCreateUser(['use scolta ai']));

    foreach (['expand-query', 'summarize', 'followup'] as $endpoint) {
      $this->drupalGet('api/scolta/v1/' . $endpoint);
      // GET on a POST-only route is 405; what matters is that the route is
      // registered and its controller and access check both resolved. A
      // dangling service reference would be a 500, and a missing route a 404.
      $this->assertContains(
        $this->getSession()->getStatusCode(),
        [403, 405],
        "api/scolta/v1/{$endpoint} must be routed on a frontend-only install"
      );
    }
  }

  /**
   * Health reports a local origin and an unbuilt index, without erroring.
   */
  public function testHealthEndpointAnswers(): void {
    $this->drupalGet('api/scolta/v1/health');
    $this->assertSession()->statusCodeEquals(200);

    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertIsArray($payload);
    $this->assertArrayHasKey('status', $payload);
  }

  /**
   * The search block renders, and says there is no index rather than crashing.
   */
  public function testSearchBlockRenders(): void {
    $this->drupalPlaceBlock('scolta_search');

    $this->drupalLogin($this->drupalCreateUser(['administer scolta ui']));
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No search index found');
  }

  /**
   * The frontend reads the backend's output_dir default without the backend.
   *
   * The one deliberate cross-object read in the split: IndexOrigin resolves
   * the local index through scolta.settings:pagefind.output_dir by config
   * name. Drupal config is global, so the read is legal with scolta absent —
   * it just finds nothing, and the service's own default has to cover it.
   */
  public function testIndexOriginDefaultsWithNoBackendConfig(): void {
    $this->assertTrue(
      $this->config('scolta.settings')->isNew(),
      'A frontend-only install must not create the backend settings object'
    );

    /** @var \Drupal\scolta_ui\Service\IndexOrigin $origin */
    $origin = \Drupal::service('scolta_ui.index_origin');

    $this->assertSame('<local>', $origin->origin());
    $this->assertFalse($origin->isRemote());
    $this->assertSame('public://scolta-pagefind', $origin->outputDirUri());
  }

  /**
   * The asset libraries build, which means the library alter ran.
   *
   * scolta_ui_library_info_alter() rewrites the deployed public:// URIs to
   * DRUPAL_ROOT-relative paths, and it reads AssetDeployer::DIRECTORY to
   * recognise them. A .module file has no namespace, so a missing import
   * there is not a warning but a fatal on every library build — and every
   * page render behind it. Resolving the library is what executes the hook.
   */
  public function testTheSearchLibraryResolves(): void {
    $library = \Drupal::service('library.discovery')
      ->getLibraryByName('scolta_ui', 'search');

    $this->assertNotFalse($library, 'The scolta_ui/search library must exist.');
    $this->assertNotEmpty($library['js']);

    foreach (array_merge($library['js'], $library['css']) as $asset) {
      $this->assertStringNotContainsString(
        ':',
        $asset['data'],
        'The alter must have resolved the stream-wrapper URI to a local path: ' . $asset['data']
      );
    }
  }

  /**
   * Only this module's permissions exist, and the AI grant was applied.
   */
  public function testPermissionsAreTheFrontendsOwn(): void {
    $permissions = \Drupal::service('user.permissions')->getPermissions();

    $this->assertArrayHasKey('administer scolta ui', $permissions);
    $this->assertArrayHasKey('use scolta ai', $permissions);
    $this->assertArrayNotHasKey('administer scolta', $permissions);

    $authenticated = Role::load('authenticated');
    $this->assertTrue(
      $authenticated->hasPermission('use scolta ai'),
      'scolta_ui_install() must grant the authenticated role AI access'
    );
  }

  /**
   * A remote index origin reaches the browser as an absolute pagefindPath.
   *
   * The consumer deployment, in the one place it is observable from Drupal:
   * the block hands scolta.js a URL string, and pointing that string at
   * another site is the whole of the frontend change.
   */
  public function testRemoteIndexOriginReachesDrupalSettings(): void {
    $this->config('scolta_ui.settings')
      ->set('index_origin', 'https://index.example.com')
      ->save();

    $this->drupalPlaceBlock('scolta_search');
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);

    $settings = $this->getDrupalSettings();
    $this->assertSame(
      'https://index.example.com/pagefind/pagefind.js',
      $settings['scolta']['pagefindPath'],
      'A remote origin must reach the browser as an absolute index URL'
    );
  }

  /**
   * A remote AI origin sends the three endpoint calls to the other site.
   */
  public function testRemoteAiOriginReachesDrupalSettings(): void {
    // The index origin has to be remote too, or the block short-circuits on
    // "no index found" and attaches no drupalSettings at all — the assertion
    // below would then fail on a missing key rather than a wrong endpoint.
    $this->config('scolta_ui.settings')
      ->set('index_origin', 'https://index.example.com')
      ->set('ai_origin', 'https://ai.example.com')
      ->save();

    $this->drupalPlaceBlock('scolta_search');
    $this->drupalGet('<front>');

    $settings = $this->getDrupalSettings();
    $this->assertSame([
      'expand' => 'https://ai.example.com/api/scolta/v1/expand-query',
      'summarize' => 'https://ai.example.com/api/scolta/v1/summarize',
      'followup' => 'https://ai.example.com/api/scolta/v1/followup',
    ], $settings['scolta']['endpoints']);
  }

}
