<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Form\ScoltaIndexSettingsForm;

/**
 * Validates the index settings form, which the backend owns alone.
 *
 * Kernel rather than unit: the entity-types rule asks the real entity type
 * manager which types are listable and the real content gatherer which are
 * configured. Only scolta is installed, which is the point — the form must
 * work on a site that builds an index and renders no search of its own.
 *
 * @group scolta
 */
class ScoltaIndexSettingsFormKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['scolta']);
    // The shipped default names node, which this test does not install; an
    // unlisted configured type is carried through on save, so it would
    // satisfy the rule on its own.
    $this->config('scolta.settings')->set('entity_types', ['user' => []])->save();
  }

  /**
   * Run the real validateForm() over submitted values; return the fields flagged.
   *
   * @param array<string, mixed> $values
   *   Submitted values; anything unnamed reads as empty.
   *
   * @return string[]
   *   The names setErrorByName() was called with.
   */
  private function validate(array $values): array {
    $reflection = new \ReflectionClass(ScoltaIndexSettingsForm::class);
    $formObject = $reflection->newInstanceWithoutConstructor();
    $formObject->setStringTranslation($this->createStub(TranslationInterface::class));
    $reflection->getProperty('contentGatherer')->setValue($formObject, $this->container->get('scolta.content_gatherer'));
    $reflection->getProperty('entityTypeManager')->setValue($formObject, $this->container->get('entity_type.manager'));

    $errors = [];
    $formState = $this->createStub(FormStateInterface::class);
    $formState->method('getValue')->willReturnCallback(
      static fn ($key, $default = NULL) => $values[$key] ?? ''
    );
    $formState->method('setErrorByName')->willReturnCallback(
      function ($name, $message = '') use (&$errors, $formState) {
        $errors[] = $name;
        return $formState;
      }
    );

    $form = [];
    $formObject->validateForm($form, $formState);
    return $errors;
  }

  public function testAnIndexWithNoEntityTypeIsRefused(): void {
    $this->assertSame(['entity_types'], $this->validate([
      'entity_types' => ['user' => ['enabled' => 0]],
    ]));
  }

  public function testOneCheckedEntityTypeIsAccepted(): void {
    $this->assertSame([], $this->validate([
      'entity_types' => ['user' => ['enabled' => 1]],
    ]));
  }

  public function testAMappingLineWithoutASeparatorIsRefused(): void {
    $this->assertSame(['field_mapping_filters'], $this->validate([
      'entity_types' => ['user' => ['enabled' => 1]],
      'field_mapping_filters' => "field_topics|topics\nfield_region",
    ]));
  }

}
