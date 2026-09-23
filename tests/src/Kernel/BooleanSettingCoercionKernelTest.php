<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * A boolean setting written as the string "false" reads as FALSE.
 *
 * `drush config:set scolta.settings ai_expand_query false` hands the config
 * API the string "false". Two things turned that into TRUE: Config::save()
 * cast it with core's (bool)-casting BooleanData, so the stored value was
 * already TRUE; and a value that skipped the cast (a settings.php override,
 * a trusted config import, a key the schema does not declare) reached
 * ScoltaConfig::fromArray(), which casts with (bool) too. The first test
 * covers the write, the second the read, both through the JS config the
 * search block emits.
 *
 * @group scolta
 */
class BooleanSettingCoercionKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    unset($GLOBALS['config']['scolta.settings']);
    parent::tearDown();
  }

  /**
   * Saving "false" through the config factory stores boolean FALSE.
   */
  public function testSavedStringFalseIsStoredAndEmittedAsFalse(): void {
    $this->config('scolta.settings')
      ->set('ai_expand_query', 'false')
      ->set('scoring.specificity_weighting', 'off')
      ->save();

    $stored = $this->container->get('config.storage')->read('scolta.settings');
    $this->assertFalse($stored['ai_expand_query']);
    $this->assertFalse($stored['scoring']['specificity_weighting']);

    $scoring = $this->jsScoringConfig();
    $this->assertFalse($scoring['AI_EXPAND_QUERY']);
    $this->assertFalse($scoring['SPECIFICITY_WEIGHTING']);
  }

  /**
   * A string "false" that bypassed the save cast still reads as FALSE.
   */
  public function testUncastStringFalseIsEmittedAsFalse(): void {
    // A settings.php override: never saved, so never cast by the schema.
    $GLOBALS['config']['scolta.settings'] = [
      'ai_expand_query' => 'false',
      'scoring' => ['specificity_weighting' => '0'],
      'auto_language_filter' => 'no',
    ];
    $this->container->get('config.factory')->reset('scolta.settings');
    $this->assertSame('false', $this->container->get('config.factory')->get('scolta.settings')->get('ai_expand_query'));

    $scoring = $this->jsScoringConfig();
    $this->assertFalse($scoring['AI_EXPAND_QUERY']);
    $this->assertFalse($scoring['SPECIFICITY_WEIGHTING']);
    $this->assertFalse($scoring['AUTO_LANGUAGE_FILTER']);
  }

  /**
   * The scoring block of the JS config, built by the real service.
   */
  private function jsScoringConfig(): array {
    // The service reads config once, in its constructor; drop any instance
    // an earlier call built so this one sees the config just written.
    $this->container->set('scolta.ai_service', NULL);
    return $this->container->get('scolta.ai_service')->getConfig()->toJsScoringConfig();
  }

}
