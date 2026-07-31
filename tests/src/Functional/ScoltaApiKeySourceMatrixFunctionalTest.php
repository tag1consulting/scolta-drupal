<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\Tests\BrowserTestBase;
use Tag1\Scolta\SetupCheck;

/**
 * Every key source, with and without stored Amazee.ai credentials, agrees.
 *
 * The defect: buildConfig() gave an explicit env/settings.php key priority
 * over stored Amazee credentials while getApiKeySource() checked Amazee first,
 * so a site running on a valid SCOLTA_API_KEY was told, in success green, that
 * it was connected to Amazee.ai — and the settings form, /health and Drush all
 * repeated it. On the Athenaeum demo that message was read as evidence the
 * environment variable was missing while a valid key was in the container the
 * whole time.
 *
 * Each cell asserts the three reporting surfaces against the same resolution:
 * the settings form, the /health payload, and the CLI check-setup row. The
 * CLI row is composed here exactly as ScoltaCommands::checkSetup() composes it
 * — that the command takes its input from resolveApiKey() rather than deriving
 * a source of its own is pinned structurally in
 * \Drupal\scolta\Tests\ApiKeySourceSingleDerivationTest.
 *
 * These have to be functional tests: the unit suite never boots Drupal, so it
 * cannot execute a resolution against a real Settings singleton and state.
 *
 * @group scolta
 * @see https://github.com/tag1consulting/scolta-php/issues/252
 */
class ScoltaApiKeySourceMatrixFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta', 'search_api'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The SCOLTA_API_KEY value to restore after the test, or FALSE if unset.
   *
   * @var string|false
   */
  protected $originalEnvKey = FALSE;

  /**
   * Fake Amazee.ai credentials. Never used to make a call in these tests.
   */
  private const AMAZEE_CREDENTIALS = [
    'litellm_token' => 'sk-amazee-stored-token',
    'litellm_api_url' => 'https://gateway.example/v1',
    'region' => 'eu',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalEnvKey = getenv('SCOLTA_API_KEY');
    putenv('SCOLTA_API_KEY');
    $this->container->get('state')->delete('scolta.amazee.credentials');

    $this->drupalLogin($this->drupalCreateUser(['administer scolta']));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->originalEnvKey !== FALSE) {
      putenv('SCOLTA_API_KEY=' . $this->originalEnvKey);
    }
    parent::tearDown();
  }

  /**
   * The four-by-two matrix.
   *
   * Written as one test because each cell reconfigures the site: writing
   * settings.php and rebuilding is far too slow to repeat per data-provider
   * row, and the point being proved is agreement across surfaces within a
   * cell rather than independence between cells.
   */
  public function testEverySourceAgreesAcrossEverySurface(): void {
    // [env key, settings.php key, amazee stored, selected provider]
    //   => expected source.
    //
    // The provider is part of the matrix because it is now what makes a
    // stored managed-gateway connection eligible at all: it is used when
    // 'amazee' is selected and ignored otherwise, whatever is stored.
    $matrix = [
      ['sk-env-key', '', FALSE, 'anthropic', 'env'],
      ['sk-env-key', '', TRUE, 'anthropic', 'env'],
      // An explicit key still outranks the gateway even when it is selected.
      ['sk-env-key', '', TRUE, 'amazee', 'env'],
      ['sk-env-key', 'sk-settings-key', FALSE, 'anthropic', 'env'],
      ['', 'sk-settings-key', FALSE, 'anthropic', 'settings'],
      ['', 'sk-settings-key', TRUE, 'amazee', 'settings'],
      ['', '', TRUE, 'amazee', 'amazee:operator'],
      // Stored but not selected: reported, never used.
      ['', '', TRUE, 'anthropic', 'none'],
      ['', '', TRUE, 'drupal_ai', 'none'],
      ['', '', FALSE, 'anthropic', 'none'],
    ];

    foreach ($matrix as [$envKey, $settingsKey, $amazeeStored, $provider, $expectedSource]) {
      $this->applyCell($envKey, $settingsKey, $amazeeStored, $provider);
      $label = sprintf(
        'env=%s settings=%s amazee=%s provider=%s',
        $envKey === '' ? 'unset' : 'set',
        $settingsKey === '' ? 'unset' : 'set',
        $amazeeStored ? 'stored' : 'absent',
        $provider
      );

      $service = $this->container->get('scolta.ai_service');
      $resolved = $service->resolveApiKey();

      // 1. The resolution itself.
      $this->assertSame($expectedSource, $resolved->source->value, $label);
      $this->assertSame($expectedSource, $service->getApiKeySource(), $label);
      $this->assertSame(
        $expectedSource === 'amazee:operator',
        $service->isAmazeeActive(),
        sprintf('%s: isAmazeeActive() must match the effective source', $label)
      );

      // The key that will actually be sent matches the reported source.
      $expectedKey = match ($expectedSource) {
        'env' => $envKey,
        'settings' => $settingsKey,
        'amazee:operator' => self::AMAZEE_CREDENTIALS['litellm_token'],
        default => '',
      };
      $this->assertSame($expectedKey, $service->getConfig()->aiApiKey, $label);

      $overridden = $amazeeStored && !str_starts_with($expectedSource, 'amazee');

      // 2. The settings form.
      $this->drupalGet('/admin/config/search/scolta');
      $page = $this->getSession()->getPage()->getContent();

      if ($expectedSource === 'amazee:operator') {
        $this->assertStringContainsString('Connected to', $page, $label);
        $this->assertStringContainsString('Amazee.ai', $page, $label);
      }
      elseif ($expectedSource === 'env') {
        $this->assertStringContainsString('SCOLTA_API_KEY environment variable', $page, $label);
      }
      elseif ($expectedSource === 'settings') {
        $this->assertStringContainsString('settings.php', $page, $label);
      }
      else {
        $this->assertStringContainsString('No API key configured', $page, $label);
      }

      if ($overridden) {
        // The whole point: stored credentials that lost are named, not hidden,
        // and the state is never rendered in success green. Match the status
        // span itself rather than the page, which carries other coloured
        // spans. Which sentence appears depends on what beat them — an
        // explicit key, or the provider simply not being Amazee.ai — because
        // the two need different fixes.
        $phrase = $expectedSource === 'none'
          ? 'stored but not in use'
          : 'stored but overridden';
        $this->assertMatchesRegularExpression(
          '#<span class="color--warning">[^<]*(<[^>]+>[^<]*)*' . preg_quote($phrase, '#') . '#',
          $page,
          sprintf('%s: an unused credential must be named, in the warning colour', $label)
        );
      }
      else {
        $this->assertStringNotContainsString('stored but overridden', $page, $label);
        $this->assertStringNotContainsString('stored but not in use', $page, $label);
      }

      // 3. The health payload.
      $this->drupalGet('/api/scolta/v1/health');
      $health = json_decode($this->getSession()->getPage()->getContent(), TRUE);
      $this->assertIsArray($health, $label);
      $this->assertSame($expectedSource, $health['ai_key_source'] ?? NULL, $label);
      $this->assertSame($overridden, $health['ai_amazee_overridden'] ?? NULL, $label);
      $this->assertSame(
        $expectedSource !== 'none',
        $health['ai_configured'] ?? NULL,
        sprintf('%s: /health must agree with the resolution about configuration', $label)
      );

      // 4. The CLI check-setup row, composed as ScoltaCommands does.
      $rows = SetupCheck::run(
        configuredBinaryPath: NULL,
        projectDir: NULL,
        aiApiKey: $service->getApiKey(),
        browserWasmDir: NULL,
        resolvedKey: $resolved,
      );
      $keyRow = NULL;
      foreach ($rows as $row) {
        if ($row['name'] === 'AI API key') {
          $keyRow = $row;
        }
      }
      $this->assertNotNull($keyRow, $label);
      $this->assertSame($resolved->describe(), $keyRow['message'], $label);
      $this->assertSame(
        $overridden || $expectedSource === 'none' ? 'warn' : 'pass',
        $keyRow['status'],
        sprintf('%s: the CLI must not report an override as a pass', $label)
      );
      if ($overridden) {
        $this->assertStringContainsString(
          $expectedSource === 'none' ? 'stored but not eligible' : 'stored but overridden by',
          $keyRow['message'],
          $label
        );
      }
    }
  }

  /**
   * Configure one cell of the matrix.
   */
  private function applyCell(string $envKey, string $settingsKey, bool $amazeeStored, string $provider): void {
    $this->writeSettings([
      'settings' => [
        'scolta.api_key' => (object) [
          'value' => $settingsKey,
          'required' => TRUE,
        ],
      ],
    ]);

    // After writeSettings(), which rewrites the same file.
    $this->applyEnvKey($envKey);

    $state = $this->container->get('state');
    if ($amazeeStored) {
      $state->set('scolta.amazee.credentials', self::AMAZEE_CREDENTIALS);
    }
    else {
      $state->delete('scolta.amazee.credentials');
    }

    $this->container->get('config.factory')
      ->getEditable('scolta.settings')
      ->set('ai_provider', $provider)
      ->save();

    $this->rebuildAll();
  }

  /**
   * Set SCOLTA_API_KEY for both processes this test observes.
   *
   * There are two, and only one of them sees a putenv() made here. The
   * assertions on the resolution itself run in the PHPUnit process, but the
   * settings page, /health and every other browser request are served by a
   * separate web server — `php -S` in CI, started before PHPUnit runs — which
   * inherits nothing this process does afterwards. An env-only cell therefore
   * used to report `env` in process and `none` over HTTP, and the matrix,
   * whose whole subject is that the surfaces agree, failed on its first row
   * for a reason that had nothing to do with the code under test.
   *
   * Drupal includes the test site's settings.php on every request, so setting
   * the variable there is what makes the server see it. The line is appended
   * after writeSettings() has rewritten the file, and the last one written
   * wins, so a later cell overrides an earlier one.
   */
  private function applyEnvKey(string $envKey): void {
    if ($envKey === '') {
      putenv('SCOLTA_API_KEY');
    }
    else {
      putenv('SCOLTA_API_KEY=' . $envKey);
    }

    $settingsFile = $this->siteDirectory . '/settings.php';
    // Drupal's own runtime requirements check removes write permission from
    // settings.php whenever it runs; writeSettings() does the same chmod.
    chmod($settingsFile, 0666);
    file_put_contents(
      $settingsFile,
      $envKey === ''
        ? "\nputenv('SCOLTA_API_KEY');\n"
        : "\nputenv('SCOLTA_API_KEY=" . addslashes($envKey) . "');\n",
      FILE_APPEND
    );
  }

}
