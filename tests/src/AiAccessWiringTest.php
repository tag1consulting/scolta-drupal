<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the wiring that makes scolta.ai_access the single AI access decision.
 *
 * The value of the service is that both gates ask it: the block that tells
 * the browser a feature exists, and the route that serves the request the
 * browser then makes. Either one going back to reading config directly would
 * restore exactly the split this replaced, and would do it silently — the
 * behaviour tests in tests/src/Functional/AiAccessFunctionalTest.php cover
 * the shipped rule, but a site's decorator is the thing that would quietly
 * stop being consulted. These are file-inspection tests; no bootstrap.
 */
class AiAccessWiringTest extends TestCase {

  private string $moduleRoot;

  protected function setUp(): void {
    $this->moduleRoot = dirname(__DIR__, 2);
  }

  /**
   * The route access check is tagged, or `_scolta_ai` silently allows.
   *
   * An untagged access check is not an error Drupal reports: the requirement
   * simply matches nothing, and every AI route falls back to the permission
   * alone.
   */
  public function testRouteAccessCheckIsTagged(): void {
    $services = Yaml::parseFile($this->moduleRoot . '/scolta.services.yml')['services'];

    $this->assertArrayHasKey('scolta.ai_feature_access_check', $services);
    $this->assertContains(
      ['name' => 'access_check', 'applies_to' => '_scolta_ai'],
      $services['scolta.ai_feature_access_check']['tags'],
      'The access check must be tagged access_check/_scolta_ai or the route requirement does nothing'
    );
  }

  /**
   * Every AI route carries both the permission and the feature requirement.
   */
  public function testAiRoutesCarryBothRequirements(): void {
    $routing = Yaml::parseFile($this->moduleRoot . '/scolta.routing.yml');

    $expected = [
      'scolta.expand' => 'expand',
      'scolta.summarize' => 'summarize',
      'scolta.followup' => 'follow_up',
    ];

    foreach ($expected as $route => $feature) {
      $requirements = $routing[$route]['requirements'];
      $this->assertSame(
        'use scolta ai',
        $requirements['_permission'],
        "Route {$route} must keep its permission requirement — the feature check adds to it, it does not replace it"
      );
      $this->assertSame(
        $feature,
        $requirements['_scolta_ai'],
        "Route {$route} must name the feature it serves"
      );
    }
  }

  /**
   * The block combines the config flag with the access answer.
   *
   * Both halves, in that order: dropping the config term would advertise a
   * feature the site has switched off, and dropping the access term restores
   * the bug where the browser is offered what the endpoint will refuse.
   */
  public function testBlockCombinesConfigWithTheAccessAnswer(): void {
    $block = file_get_contents($this->moduleRoot . '/src/Plugin/Block/ScoltaSearchBlock.php');

    $this->assertStringContainsString(
      'aiAccess->access($this->currentUser, AiAccessInterface::FEATURE_EXPAND)',
      $block,
      'ScoltaSearchBlock must ask scolta.ai_access before advertising query expansion'
    );
    $this->assertStringContainsString(
      'aiAccess->access($this->currentUser, AiAccessInterface::FEATURE_SUMMARIZE)',
      $block,
      'ScoltaSearchBlock must ask scolta.ai_access before advertising the AI overview'
    );
    foreach (['AI_EXPAND_QUERY', 'AI_SUMMARIZE'] as $flag) {
      $this->assertMatchesRegularExpression(
        "/\\\$scoring\\['{$flag}'\\] = \\\$scoring\\['{$flag}'\\] && \\\$\\w+Access->isAllowed\\(\\);/",
        $block,
        "The emitted {$flag} must be the config flag AND the access answer"
      );
    }
  }

  /**
   * The shipped rule states the permission and nothing about config.
   *
   * ai_expand_query, ai_summarize and max_follow_ups describe what the site
   * offers, and AiEndpointHandler already answers 404 and 429 for them.
   * Restating any of them here refuses those requests at routing instead,
   * which is a behaviour change for every site that has decorated nothing —
   * and max_follow_ups cannot be expressed as access at all, since the real
   * rule counts messages in the request body.
   */
  public function testTheShippedRuleDoesNotRestateConfig(): void {
    $access = file_get_contents($this->moduleRoot . '/src/Access/AiAccess.php');

    $this->assertStringContainsString(
      "allowedIfHasPermission(\$account, 'use scolta ai')",
      $access,
      'The shipped rule is the permission check'
    );
    foreach (['ai_expand_query', 'ai_summarize', 'max_follow_ups'] as $key) {
      $this->assertStringNotContainsString(
        "get('{$key}')",
        $access,
        "AiAccess must not read {$key}: config says what the site offers, access says who may ask"
      );
    }
  }

  /**
   * The access answer's cacheability reaches the block's render array.
   *
   * Without this the block is cached under the first visitor's answer and
   * served to everyone — the failure mode of a per-user decorator, and one
   * that looks like a caching mystery rather than an access bug.
   */
  public function testBlockBubblesTheAccessCacheability(): void {
    $block = file_get_contents($this->moduleRoot . '/src/Plugin/Block/ScoltaSearchBlock.php');

    $this->assertStringContainsString('CacheableMetadata::createFromRenderArray($build)', $block);
    $this->assertStringContainsString('addCacheableDependency($expandAccess)', $block);
    $this->assertStringContainsString('addCacheableDependency($summarizeAccess)', $block);
    $this->assertStringContainsString('applyTo($build)', $block);
  }

}
