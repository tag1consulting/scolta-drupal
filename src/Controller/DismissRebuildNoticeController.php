<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles per-user dismissal of the persistent rebuild notice.
 *
 * Records a dismissal in user.data keyed to the notice_id so that
 * hook_page_top() skips rendering the notice for this user on future
 * page loads. Other admins continue to see the notice until they
 * dismiss it themselves.
 *
 * @since 0.2.4
 * @stability experimental
 */
class DismissRebuildNoticeController extends ControllerBase {

  /**
   * Record dismissal and redirect back to the Scolta settings page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the settings page (or ?destination if provided).
   */
  public function handle(Request $request): RedirectResponse {
    $notice_id = $request->query->get('notice_id', '');
    $notice_id = preg_replace('/[^a-zA-Z0-9_.]/', '', (string) $notice_id);

    if ($notice_id !== '') {
      $current_notice = $this->state()->get('scolta.rebuild_notice');
      // Only record dismissal if this notice_id is still the active one.
      if (is_array($current_notice) && ($current_notice['notice_id'] ?? '') === $notice_id) {
        /** @var \Drupal\user\UserDataInterface $user_data */
        $user_data = \Drupal::service('user.data');
        $user_data->set('scolta', $this->currentUser()->id(), 'dismissed_rebuild_notice', $notice_id);
      }
    }

    $destination = $request->query->get('destination', '');
    if ($destination && str_starts_with($destination, '/')) {
      return new RedirectResponse($destination);
    }

    return new RedirectResponse(Url::fromRoute('scolta.settings')->toString());
  }

}
