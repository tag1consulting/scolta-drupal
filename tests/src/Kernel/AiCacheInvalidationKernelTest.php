<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\scolta\Cache\DrupalCacheDriver;
use Drupal\scolta\Controller\AiApiControllerBase;

/**
 * What retires a cached AI response, and what leaves it alone.
 *
 * The AI controllers write expand/summarize results through DrupalCacheDriver
 * into cache.default, tagged with the config their system prompt is built
 * from. A save of that config must retire them; an unrelated config save and
 * a search-index tag invalidation (what a build emits) must not.
 *
 * @group scolta
 */
class AiCacheInvalidationKernelTest extends KernelTestBase {

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
   * A driver tagged like the AI controllers', writing to the real bin.
   */
  protected function driver(): DrupalCacheDriver {
    return new DrupalCacheDriver($this->container->get('cache.default'), AiApiControllerBase::CACHE_TAGS);
  }

  public function testSettingsSaveRetiresCachedAiResponses(): void {
    $driver = $this->driver();
    $driver->set('scolta_expand_0_abc', ['terms' => ['math']], 3600);
    $this->assertSame(['terms' => ['math']], $driver->get('scolta_expand_0_abc'));

    $this->config('scolta.settings')->set('ai_model', 'claude-haiku-5-5')->save();

    $this->assertNull($driver->get('scolta_expand_0_abc'), 'A scolta.settings save must retire cached AI responses: the prompt they were generated with may have changed');
  }

  public function testSiteNameSaveRetiresCachedAiResponses(): void {
    $driver = $this->driver();
    $driver->set('scolta_summarize_0_abc', ['summary' => 'A summary.'], 3600);

    $this->config('system.site')->set('name', 'Renamed site')->save();

    $this->assertNull($driver->get('scolta_summarize_0_abc'), 'The default prompts embed the site name, so a system.site save must retire cached AI responses');
  }

  public function testIndexInvalidationLeavesCachedAiResponsesAlone(): void {
    $driver = $this->driver();
    $driver->set('scolta_expand_0_abc', ['terms' => ['math']], 3600);

    // What every build, full or incremental, emits for the search block.
    $this->container->get('cache_tags.invalidator')->invalidateTags(['scolta_search_index']);
    // An unrelated config save.
    $this->config('system.date')->set('first_day', 1)->save();

    $this->assertSame(['terms' => ['math']], $driver->get('scolta_expand_0_abc'), 'Neither an index change nor unrelated config may retire a cached AI response');
  }

}
