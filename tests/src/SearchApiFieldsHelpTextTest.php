<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that the Search API index fields form gets a Scolta help-text notice.
 *
 * File-inspection tests confirm the hook implementation structure without
 * requiring a full Drupal bootstrap. The hook must exist, target the correct
 * form ID, guard against non-Scolta backends, and inject the right notice.
 */
class SearchApiFieldsHelpTextTest extends TestCase {

  private string $moduleSource;

  protected function setUp(): void {
    $this->moduleSource = file_get_contents(dirname(__DIR__, 2) . '/scolta.module');
  }

  // -------------------------------------------------------------------
  // Hook exists with correct name.
  // -------------------------------------------------------------------

  public function testFormAlterHookExists(): void {
    $this->assertStringContainsString(
      'function scolta_form_search_api_index_fields_alter(',
      $this->moduleSource,
      'scolta.module must implement hook_form_FORM_ID_alter for search_api_index_fields'
    );
  }

  // -------------------------------------------------------------------
  // Hook guards against non-Scolta backends.
  // -------------------------------------------------------------------

  public function testHookChecksBackendId(): void {
    $this->assertStringContainsString(
      'scolta_pagefind',
      $this->moduleSource,
      'The form alter hook must check for the scolta_pagefind backend ID before adding the notice'
    );
  }

  /**
   * The hook must reach the server through an accessor IndexInterface has.
   *
   * It used to call getServer(), which search_api does not define — every visit
   * to any index's "Manage fields" form raised an Error, and the hook's
   * catch (\Exception) does not catch an Error. getServerInstance() is the real
   * accessor: NULL when the index has no server, SearchApiException (which the
   * catch does handle) when the server cannot be loaded.
   */
  public function testHookCallsGetServerInstance(): void {
    preg_match('/function scolta_form_search_api_index_fields_alter\b[^{]*\{(.*?)(?=\n\/\*\*|\nfunction |\Z)/s', $this->moduleSource, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate scolta_form_search_api_index_fields_alter() body');
    $this->assertStringContainsString(
      'getServerInstance()',
      $body,
      'The form alter hook must call getServerInstance() to retrieve the server for the backend ID check'
    );
    $this->assertTrue(
      method_exists(\Drupal\search_api\IndexInterface::class, 'getServerInstance')
      || !interface_exists(\Drupal\search_api\IndexInterface::class),
      'getServerInstance() must exist on IndexInterface when search_api is loadable'
    );
  }

  public function testHookReturnsEarlyForOtherBackends(): void {
    preg_match('/function scolta_form_search_api_index_fields_alter\b[^{]*\{(.*?)(?=\n\/\*\*|\nfunction |\Z)/s', $this->moduleSource, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate scolta_form_search_api_index_fields_alter() body');
    // Early-return when backend is not scolta_pagefind: the condition must
    // include a return so non-Scolta indexes are not affected.
    $this->assertMatchesRegularExpression(
      '/scolta_pagefind.*return|return.*scolta_pagefind/s',
      $body,
      'The form alter hook must return early when the backend is not scolta_pagefind'
    );
  }

  public function testHookWrapsServerLookupInTryCatch(): void {
    preg_match('/function scolta_form_search_api_index_fields_alter\b[^{]*\{(.*?)(?=\n\/\*\*|\nfunction |\Z)/s', $this->moduleSource, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate scolta_form_search_api_index_fields_alter() body');
    $this->assertStringContainsString(
      'catch',
      $body,
      'The form alter hook must wrap getServer() in try-catch because unconfigured indexes throw on getServer()'
    );
  }

  // -------------------------------------------------------------------
  // Notice content is correct.
  // -------------------------------------------------------------------

  public function testHookAddsNoticeElement(): void {
    preg_match('/function scolta_form_search_api_index_fields_alter\b[^{]*\{(.*?)(?=\n\/\*\*|\nfunction |\Z)/s', $this->moduleSource, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate scolta_form_search_api_index_fields_alter() body');
    $this->assertStringContainsString(
      'scolta_fields_notice',
      $body,
      'The form alter hook must add a $form[\'scolta_fields_notice\'] element'
    );
  }

  public function testNoticeExplainsFieldsNotRequired(): void {
    preg_match('/function scolta_form_search_api_index_fields_alter\b[^{]*\{(.*?)(?=\n\/\*\*|\nfunction |\Z)/s', $this->moduleSource, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate scolta_form_search_api_index_fields_alter() body');
    // The text must explain that field selection is not required.
    $this->assertStringContainsString(
      'not required',
      $body,
      'The notice text must tell the admin that field selection is not required'
    );
  }

  public function testNoticeExplainsRenderedContent(): void {
    preg_match('/function scolta_form_search_api_index_fields_alter\b[^{]*\{(.*?)(?=\n\/\*\*|\nfunction |\Z)/s', $this->moduleSource, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate scolta_form_search_api_index_fields_alter() body');
    $this->assertStringContainsString(
      'rendered',
      $body,
      'The notice text must explain that Scolta indexes the rendered content'
    );
  }

  public function testNoticeHasHighNegativeWeight(): void {
    preg_match('/function scolta_form_search_api_index_fields_alter\b[^{]*\{(.*?)(?=\n\/\*\*|\nfunction |\Z)/s', $this->moduleSource, $m);
    $body = $m[1] ?? '';
    $this->assertNotEmpty($body, 'Could not locate scolta_form_search_api_index_fields_alter() body');
    $this->assertStringContainsString(
      '#weight',
      $body,
      'The notice element must set #weight so it appears at the top of the form'
    );
    // A negative weight ensures it renders above the field table.
    $this->assertMatchesRegularExpression(
      '/#weight.*-\d+|-\d+.*#weight/s',
      $body,
      'The notice #weight must be negative so it renders before the fields table'
    );
  }

}
