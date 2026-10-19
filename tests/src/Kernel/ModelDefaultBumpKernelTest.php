<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Form\ScoltaSettingsForm;

/**
 * Coverage for scolta_update_10010() and the shipped model defaults.
 *
 * The hook rewrites ai_model / ai_expansion_model only where a site still
 * holds the dated Claude 4.5 defaults an earlier release shipped; any other
 * value is an operator's choice and stays. The install defaults must equal
 * the form constants, which the form reads from scolta-php.
 *
 * @group scolta
 */
class ModelDefaultBumpKernelTest extends KernelTestBase {

  private const OLD_MODEL = 'claude-sonnet-4-5-20250929';
  private const OLD_EXPANSION_MODEL = 'claude-haiku-4-5-20251001';

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
   * config/install ships the same IDs the form constants hold.
   */
  public function testInstallDefaultsMatchFormConstants(): void {
    $config = $this->config('scolta.settings');
    $this->assertSame(ScoltaSettingsForm::DEFAULT_AI_MODEL, $config->get('ai_model'));
    $this->assertSame(ScoltaSettingsForm::DEFAULT_AI_EXPANSION_MODEL, $config->get('ai_expansion_model'));
    $this->assertNotSame(self::OLD_MODEL, $config->get('ai_model'), 'The shipped default has moved off the dated ID');
  }

  /**
   * A site on both old defaults is moved to both new ones.
   */
  public function testOldDefaultsAreRewritten(): void {
    $this->setModels(self::OLD_MODEL, self::OLD_EXPANSION_MODEL);

    $message = (string) $this->runUpdate();
    $config = $this->config('scolta.settings');

    $this->assertSame(ScoltaSettingsForm::DEFAULT_AI_MODEL, $config->get('ai_model'));
    $this->assertSame(ScoltaSettingsForm::DEFAULT_AI_EXPANSION_MODEL, $config->get('ai_expansion_model'));
    $this->assertStringContainsString(self::OLD_MODEL, $message);
    $this->assertStringContainsString(self::OLD_EXPANSION_MODEL, $message);
  }

  /**
   * Each key is judged on its own.
   */
  public function testOnlyTheStaleKeyIsRewritten(): void {
    $this->setModels('claude-opus-4-1-20250805', self::OLD_EXPANSION_MODEL);

    $this->runUpdate();
    $config = $this->config('scolta.settings');

    $this->assertSame('claude-opus-4-1-20250805', $config->get('ai_model'));
    $this->assertSame(ScoltaSettingsForm::DEFAULT_AI_EXPANSION_MODEL, $config->get('ai_expansion_model'));
  }

  /**
   * An operator's own choices, including an empty expansion model, stay.
   */
  public function testOtherValuesAreLeftAlone(): void {
    $this->setModels('gpt-4o', '');

    $before = $this->config('scolta.settings')->getRawData();
    $message = (string) $this->runUpdate();

    $this->assertSame($before, $this->config('scolta.settings')->getRawData());
    $this->assertStringContainsString('nothing changed', $message);
  }

  /**
   * Running the hook twice changes nothing the second time.
   */
  public function testSecondRunIsANoOp(): void {
    $this->setModels(self::OLD_MODEL, self::OLD_EXPANSION_MODEL);
    $this->runUpdate();
    $after = $this->config('scolta.settings')->getRawData();

    $this->runUpdate();

    $this->assertSame($after, $this->config('scolta.settings')->getRawData());
  }

  private function setModels(string $aiModel, string $aiExpansionModel): void {
    \Drupal::configFactory()->getEditable('scolta.settings')
      ->set('ai_model', $aiModel)
      ->set('ai_expansion_model', $aiExpansionModel)
      ->save();
  }

  /**
   * Loads scolta.install and runs the hook.
   *
   * @return mixed
   *   Whatever the hook returned.
   */
  private function runUpdate() {
    \Drupal::moduleHandler()->loadInclude('scolta', 'install');
    $this->assertTrue(function_exists('scolta_update_10010'), 'scolta.install must define scolta_update_10010()');
    return scolta_update_10010();
  }

}
