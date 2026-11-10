<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta_ui\Form\ScoltaSettingsForm;

/**
 * The shipped model defaults and scolta_update_10010().
 *
 * @group scolta
 */
class ModelDefaultBumpKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta', 'scolta_ui'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta', 'scolta_ui']);
  }

  /**
   * config/install ships the IDs the form constants hold.
   *
   * The form reads DEFAULT_AI_MODEL from scolta-php, so this is what keeps
   * the install YAML and the library from drifting apart.
   */
  public function testInstallDefaultsMatchFormConstants(): void {
    $config = $this->config('scolta_ui.settings');
    $this->assertSame(ScoltaSettingsForm::DEFAULT_AI_MODEL, $config->get('ai_model'));
    $this->assertSame(ScoltaSettingsForm::DEFAULT_AI_EXPANSION_MODEL, $config->get('ai_expansion_model'));
  }

  /**
   * The hook rewrites a stale default and leaves an operator's choice alone.
   */
  public function testUpdateHookRewritesOnlyTheOldDefaults(): void {
    \Drupal::configFactory()->getEditable('scolta_ui.settings')
      ->set('ai_model', 'claude-opus-4-1-20250805')
      ->set('ai_expansion_model', 'claude-haiku-4-5-20251001')
      ->save();

    \Drupal::moduleHandler()->loadInclude('scolta', 'install');
    scolta_update_10010();
    $config = $this->config('scolta_ui.settings');

    $this->assertSame('claude-opus-4-1-20250805', $config->get('ai_model'), 'An operator choice is kept');
    $this->assertSame(ScoltaSettingsForm::DEFAULT_AI_EXPANSION_MODEL, $config->get('ai_expansion_model'));
  }

}
