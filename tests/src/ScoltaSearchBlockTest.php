<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\scolta\Access\AiAccessInterface;
use Drupal\scolta\Plugin\Block\ScoltaSearchBlock;
use Drupal\scolta\Service\IndexLocator;
use Drupal\scolta\Service\ScoltaAiService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Behavioral tests for ScoltaSearchBlock.
 *
 * Constructs the real block with stubbed services and asserts on build()'s
 * render array: container markup, attached libraries, drupalSettings
 * payload, cache metadata, and the facet_mode/attribution/language behavior
 * documented on the class. Url::fromRoute()->toString() requires a service
 * container even outside a full Drupal bootstrap, so setUp() installs a
 * minimal one with a stubbed 'url_generator' service; this is the only
 * container-shaped concession the test makes.
 */
class ScoltaSearchBlockTest extends TestCase {

  protected function setUp(): void {
    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')
      ->willReturnCallback(fn (string $name): string => '/' . str_replace('.', '/', $name));

    $container = new Container();
    $container->set('url_generator', $urlGenerator);
    \Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
  }

  /**
   * A stub TranslationInterface that returns the untranslated string as-is.
   *
   * Lets $this->t() run inside build() without a real translation service.
   */
  private function stubTranslation(): TranslationInterface {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    return $translation;
  }

  /**
   * Builds a real ScoltaSearchBlock with stubbed collaborators.
   *
   * @param array<string, mixed> $scoltaSettings
   *   Keyed values returned by $configFactory->get('scolta.settings')->get().
   * @param bool $indexExists
   *   What IndexLocator::exists() reports.
   */
  private function createBlock(
    ScoltaConfig $scoltaConfig,
    array $scoltaSettings = [],
    bool $indexExists = TRUE,
  ): ScoltaSearchBlock {
    $scoltaSettings += [
      'pagefind.output_dir' => '/tmp/scolta-pagefind-test',
      'facet_mode' => 'eager',
    ];

    $scoltaSettingsConfig = $this->createMock(ImmutableConfig::class);
    $scoltaSettingsConfig->method('get')
      ->willReturnCallback(fn (string $key) => $scoltaSettings[$key] ?? NULL);

    $systemSiteConfig = $this->createMock(ImmutableConfig::class);
    $systemSiteConfig->method('get')
      ->willReturnMap([['name', 'Fallback Site Name']]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnMap([
        ['scolta.settings', $scoltaSettingsConfig],
        ['system.site', $systemSiteConfig],
      ]);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->expects($this->once())
      ->method('getCurrentLanguage')
      ->with(LanguageInterface::TYPE_CONTENT)
      ->willReturn($language);

    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $fileUrlGenerator->method('generateString')
      ->willReturnCallback(fn (string $uri): string => '/files/' . ltrim(str_replace('public://', '', $uri), '/'));

    $aiService = $this->createMock(ScoltaAiService::class);
    $aiService->method('getConfig')->willReturn($scoltaConfig);

    $currentUser = $this->createMock(AccountInterface::class);

    $aiAccess = $this->createMock(AiAccessInterface::class);
    $aiAccess->method('access')
      ->willReturnMap([
        [$currentUser, AiAccessInterface::FEATURE_EXPAND, AccessResult::allowed()],
        [$currentUser, AiAccessInterface::FEATURE_SUMMARIZE, AccessResult::allowed()],
      ]);

    $indexLocator = $this->createMock(IndexLocator::class);
    $indexLocator->method('exists')->willReturn($indexExists);

    $streamWrapperManager = $this->createMock(StreamWrapperManagerInterface::class);

    $block = new ScoltaSearchBlock(
      [],
      'scolta_search',
      ['provider' => 'scolta'],
      $aiService,
      $fileUrlGenerator,
      $configFactory,
      $languageManager,
      $currentUser,
      $streamWrapperManager,
      $indexLocator,
      $aiAccess,
    );
    $block->setStringTranslation($this->stubTranslation());

    return $block;
  }

  // -------------------------------------------------------------------
  // build(): render array shape.
  // -------------------------------------------------------------------

  public function testBuildRendersContainerDivAndAttachesLibraries(): void {
    $block = $this->createBlock(new ScoltaConfig());
    $build = $block->build();

    $this->assertStringContainsString('<div id="scolta-search"></div>', $build['#markup']);
    $this->assertSame(
      ['scolta/search', 'scolta/drupal_bridge'],
      $build['#attached']['library'],
    );
  }

  public function testBuildDrupalSettingsIncludesExpectedKeys(): void {
    $block = $this->createBlock(new ScoltaConfig());
    $build = $block->build();

    $settings = $build['#attached']['drupalSettings']['scolta'];

    $this->assertArrayHasKey('endpoints', $settings);
    $this->assertSame(
      ['expand', 'summarize', 'followup'],
      array_keys($settings['endpoints']),
    );
    $this->assertArrayHasKey('scoring', $settings);
    $this->assertSame('/tmp/scolta-pagefind-test/pagefind/pagefind.js', $settings['pagefindPath']);
    $this->assertSame('/files/scolta-assets/wasm/scolta_core.js', $settings['wasmPath']);
    $this->assertSame('en', $settings['currentLanguage']);
  }

  public function testBuildDrupalSettingsSiteNameFallsBackToSystemSite(): void {
    $config = new ScoltaConfig();
    $config->siteName = '';
    $block = $this->createBlock($config);
    $build = $block->build();

    $this->assertSame('Fallback Site Name', $build['#attached']['drupalSettings']['scolta']['siteName']);
  }

  public function testBuildDrupalSettingsSiteNamePrefersScoltaConfig(): void {
    $config = new ScoltaConfig();
    $config->siteName = 'Configured Site Name';
    $block = $this->createBlock($config);
    $build = $block->build();

    $this->assertSame('Configured Site Name', $build['#attached']['drupalSettings']['scolta']['siteName']);
  }

  public function testBuildDeclaresCacheTagsAndContexts(): void {
    $block = $this->createBlock(new ScoltaConfig());
    $build = $block->build();

    $this->assertContains('config:scolta.settings', $build['#cache']['tags']);
    $this->assertContains('scolta_search_index', $build['#cache']['tags']);
    $this->assertContains('languages:language_content', $build['#cache']['contexts']);
  }

  // -------------------------------------------------------------------
  // facet_mode clamping.
  // -------------------------------------------------------------------

  public function testFacetModeClampsUnrecognizedValueToEager(): void {
    $block = $this->createBlock(new ScoltaConfig(), ['facet_mode' => 'bogus']);
    $build = $block->build();

    $this->assertSame('eager', $build['#attached']['drupalSettings']['scolta']['facetMode']);
  }

  public function testFacetModePassesThroughDeferred(): void {
    $block = $this->createBlock(new ScoltaConfig(), ['facet_mode' => 'deferred']);
    $build = $block->build();

    $this->assertSame('deferred', $build['#attached']['drupalSettings']['scolta']['facetMode']);
  }

  // -------------------------------------------------------------------
  // Language: getCurrentLanguage() assertion lives in createBlock()'s
  // ->expects($this->once())->with(LanguageInterface::TYPE_CONTENT), which
  // fails the test if build() ever asks for a different language type.
  // -------------------------------------------------------------------

  public function testCurrentLanguageRequestsContentLanguageType(): void {
    $block = $this->createBlock(new ScoltaConfig());
    $block->build();
    // No additional assertion needed: the mock in createBlock() already
    // expects exactly one call to getCurrentLanguage(TYPE_CONTENT).
    $this->addToAssertionCount(1);
  }

  // -------------------------------------------------------------------
  // Attribution — issue scolta-php#102.
  // -------------------------------------------------------------------

  public function testAttributionMarkupPresentWhenEnabled(): void {
    $config = new ScoltaConfig();
    $config->showAttribution = TRUE;
    $block = $this->createBlock($config);
    $build = $block->build();

    $this->assertStringContainsString('scolta-attribution', $build['#markup']);
    $this->assertStringContainsString('Powered by Scolta', $build['#markup']);
  }

  public function testAttributionMarkupAbsentWhenDisabled(): void {
    $config = new ScoltaConfig();
    $config->showAttribution = FALSE;
    $block = $this->createBlock($config);
    $build = $block->build();

    $this->assertStringNotContainsString('scolta-attribution', $build['#markup']);
  }

  // -------------------------------------------------------------------
  // create(): container wiring.
  // -------------------------------------------------------------------

  public function testCreateRequestsExpectedServices(): void {
    $requestedIds = [];

    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')
      ->willReturnCallback(function (string $id) use (&$requestedIds) {
        $requestedIds[] = $id;
        return match ($id) {
          'scolta.ai_service' => $this->createMock(ScoltaAiService::class),
          'file_url_generator' => $this->createMock(FileUrlGeneratorInterface::class),
          'config.factory' => $this->createMock(ConfigFactoryInterface::class),
          'language_manager' => $this->createMock(LanguageManagerInterface::class),
          'current_user' => $this->createMock(AccountInterface::class),
          'stream_wrapper_manager' => $this->createMock(StreamWrapperManagerInterface::class),
          'scolta.index_locator' => $this->createMock(IndexLocator::class),
          'scolta.ai_access' => $this->createMock(AiAccessInterface::class),
          default => NULL,
        };
      });

    $block = ScoltaSearchBlock::create($container, [], 'scolta_search', ['provider' => 'scolta']);

    $this->assertInstanceOf(ScoltaSearchBlock::class, $block);
    $this->assertSame(
      [
        'scolta.ai_service',
        'file_url_generator',
        'config.factory',
        'language_manager',
        'current_user',
        'stream_wrapper_manager',
        'scolta.index_locator',
        'scolta.ai_access',
      ],
      $requestedIds,
    );
  }

}
