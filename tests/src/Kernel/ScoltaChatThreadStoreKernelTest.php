<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Tag1\Scolta\Chat\ChatOwner;
use Tag1\Scolta\Chat\ThreadStoreInterface;

/**
 * Chat threads in the expirable key value store, as the service is wired.
 *
 * @group scolta
 */
class ScoltaChatThreadStoreKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'scolta'];

  /**
   * The store under test.
   */
  private ThreadStoreInterface $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->store = $this->container->get('scolta.chat_thread_store');
  }

  public function testSaveLoadAndDelete(): void {
    $owner = ChatOwner::forUser(7);
    $key = $owner->threadKey(ChatOwner::newThreadId());

    $this->assertNull($this->store->load($key));
    $this->store->save($key, ['schema' => 1, 'summary' => 'kept'], 3600);
    $this->assertSame(['schema' => 1, 'summary' => 'kept'], $this->store->load($key));

    $this->store->delete($key);
    $this->assertNull($this->store->load($key));
    $this->store->delete($key);
  }

  public function testAThreadExpiresWithItsLifetime(): void {
    $key = ChatOwner::forUser(7)->threadKey(ChatOwner::newThreadId());
    $this->store->save($key, ['schema' => 1], 3600);

    $row = $this->container->get('database')->select('key_value_expire', 'k')
      ->fields('k', ['expire'])
      ->condition('collection', 'scolta_chat')
      ->condition('name', $key)
      ->execute()
      ->fetchField();
    $this->assertEqualsWithDelta(time() + 3600, (int) $row, 5, 'The lifetime reaches the store');

    $this->container->get('database')->update('key_value_expire')
      ->fields(['expire' => time() - 1])
      ->condition('collection', 'scolta_chat')
      ->condition('name', $key)
      ->execute();
    $this->assertNull($this->store->load($key));
  }

  public function testAnotherOwnersKeyReadsNothing(): void {
    $thread = ChatOwner::newThreadId();
    $this->store->save(ChatOwner::forUser(7)->threadKey($thread), ['schema' => 1], 3600);

    $this->assertNull($this->store->load(ChatOwner::forUser(8)->threadKey($thread)));
    $this->assertNull($this->store->load(ChatOwner::fromCookie(str_repeat('a', 64))->threadKey($thread)));
  }

}
