<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\Component\Serialization\PhpSerialize;
use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\AiProvider\Amazee\DrupalConfigStorage;
use Drupal\scolta\Form\AmazeeSettingsForm;

/**
 * The Amazee.ai settings form survives the serialization a rebuild requires.
 *
 * Every submit handler on the form rebuilds or redirects, and a rebuilt form
 * is cached, which serializes the form object. The form's collaborators wrap
 * the Guzzle client, whose handler stack holds closures, so the form is only
 * serializable when each collaborator is a container service that
 * DependencySerializationTrait swaps for its id and restores afterwards.
 *
 * @group scolta
 */
class AmazeeSettingsFormSerializationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta'];

  /**
   * A serialize/unserialize round trip returns a working form.
   */
  public function testFormSurvivesSerializationRoundTrip(): void {
    $form = AmazeeSettingsForm::create($this->container);

    // The serializer the form cache's key/value store uses.
    $restored = PhpSerialize::decode(PhpSerialize::encode($form));

    $this->assertInstanceOf(AmazeeSettingsForm::class, $restored);
    $storage = (new \ReflectionProperty(AmazeeSettingsForm::class, 'storage'))->getValue($restored);
    $this->assertInstanceOf(DrupalConfigStorage::class, $storage);
    // Usable, not merely present: the restored storage reads state.
    $this->assertNull($storage->load());
  }

}
