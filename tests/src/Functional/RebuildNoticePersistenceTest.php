<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta\Functional;

use Drupal\scolta\Batch\ScoltaBatchOperations;
use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for rebuild notice persistence and per-user dismissal.
 *
 * Pre-fix: ScoltaBatchOperations stored notices in Messenger (flash — vanishes
 * after first render). Post-fix: notices are stored in State and persist until
 * explicitly dismissed by each admin user.
 *
 * @group scolta
 */
class RebuildNoticePersistenceTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Second admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser2;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser(['administer scolta']);
    $this->adminUser2 = $this->drupalCreateUser(['administer scolta']);
    // Clear any pre-existing notice state.
    \Drupal::state()->delete('scolta.rebuild_notice');
  }

  /**
   * Mirror of WP test_notice_persists_across_page_loads_until_dismissed.
   *
   * Pre-fix: Messenger flash — notice missing on second load.
   * Post-fix: State-backed — notice present on all loads until dismissed.
   */
  public function testNoticePersistsAcrossPageLoads(): void {
    $this->drupalLogin($this->adminUser);

    // Simulate a successful rebuild by writing directly to State.
    \Drupal::state()->set('scolta.rebuild_notice', ScoltaBatchOperations::buildNoticeData(
      'ok',
      'Index rebuilt: 10 pages.'
    ));

    // First load.
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextContains('Index rebuilt');

    // Second load — must still show (State-backed, not flash).
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextContains('Index rebuilt');

    // Third load.
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextContains('Index rebuilt');
  }

  /**
   * Mirror of WP test_dismissed_notice_does_not_show.
   *
   * Pre-fix: no dismiss mechanism.
   * Post-fix: dismiss route sets user.data; notice gone on next load.
   */
  public function testDismissedNoticeDoesNotShow(): void {
    $this->drupalLogin($this->adminUser);

    \Drupal::state()->set('scolta.rebuild_notice', ScoltaBatchOperations::buildNoticeData(
      'ok',
      'Index rebuilt: 5 pages.'
    ));
    $notice_id = \Drupal::state()->get('scolta.rebuild_notice')['notice_id'];

    // Visit dismiss route.
    $this->drupalGet('/admin/config/search/scolta/dismiss-rebuild-notice', [
      'query' => ['notice_id' => $notice_id],
    ]);

    // Follow redirect back to settings.
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextNotContains('Index rebuilt');
  }

  /**
   * Mirror of WP test_notice_reappears_after_new_rebuild.
   *
   * Post-fix: new rebuild sets new notice_id; dismissed user sees fresh notice.
   */
  public function testNoticeReappearsAfterNewRebuild(): void {
    $this->drupalLogin($this->adminUser);

    // Rebuild 1.
    \Drupal::state()->set('scolta.rebuild_notice', ScoltaBatchOperations::buildNoticeData(
      'ok',
      'Index rebuilt: 5 pages.'
    ));
    $notice_id_1 = \Drupal::state()->get('scolta.rebuild_notice')['notice_id'];

    // Dismiss rebuild-1.
    $this->drupalGet('/admin/config/search/scolta/dismiss-rebuild-notice', [
      'query' => ['notice_id' => $notice_id_1],
    ]);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextNotContains('5 pages');

    // Rebuild 2.
    \Drupal::state()->set('scolta.rebuild_notice', ScoltaBatchOperations::buildNoticeData(
      'ok',
      'Index rebuilt: 8 pages.'
    ));

    // New notice should show (different notice_id).
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextContains('8 pages');
  }

  /**
   * Mirror of WP test_other_users_render_does_not_consume_notice.
   *
   * Post-fix: User 1 viewing the notice does not remove it for User 2.
   */
  public function testOtherUsersViewDoesNotConsumeNotice(): void {
    \Drupal::state()->set('scolta.rebuild_notice', ScoltaBatchOperations::buildNoticeData(
      'ok',
      'Index rebuilt: 5 pages.'
    ));

    // User 1 views the notice.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextContains('Index rebuilt');

    // User 2 must still see it.
    $this->drupalLogout();
    $this->drupalLogin($this->adminUser2);
    $this->drupalGet('/admin/config/search/scolta');
    $this->assertSession()->pageTextContains('Index rebuilt');
  }

}
