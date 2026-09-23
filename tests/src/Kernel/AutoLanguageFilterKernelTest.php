<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Form\ScoltaSettingsForm;

/**
 * The auto_language_filter setting is declared, editable, and emitted.
 *
 * The widget flag reached the browser when set, because buildConfig() passes
 * the whole settings array to ScoltaConfig::fromArray(), but the module never
 * declared it: no schema entry, no install default, no form field. These
 * tests drive the real settings form and the real update hook.
 *
 * @group scolta
 */
class AutoLanguageFilterKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'scolta']);
  }

  /**
   * Saving the form with the box ticked reaches the JS config as TRUE.
   */
  public function testFormSubmitReachesJsConfig(): void {
    $this->assertFalse($this->jsScoringConfig()['AUTO_LANGUAGE_FILTER']);

    $formState = (new FormState())->setValues(['auto_language_filter' => 1]);
    $this->container->get('form_builder')->submitForm(ScoltaSettingsForm::class, $formState);
    $this->assertSame([], $formState->getErrors());

    $this->assertTrue($this->config('scolta.settings')->get('auto_language_filter'));
    $this->assertTrue($this->jsScoringConfig()['AUTO_LANGUAGE_FILTER']);
  }

  /**
   * The update hook adds the default and keeps a value a site already set.
   */
  public function testUpdateHookAddsDefaultAndKeepsExistingValue(): void {
    $this->container->get('module_handler')->loadInclude('scolta', 'install');

    $this->config('scolta.settings')->clear('auto_language_filter')->save();
    scolta_update_10009();
    $this->assertFalse($this->config('scolta.settings')->get('auto_language_filter'));

    $this->config('scolta.settings')->set('auto_language_filter', TRUE)->save();
    scolta_update_10009();
    $this->assertTrue($this->config('scolta.settings')->get('auto_language_filter'));
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
