<?php

declare(strict_types=1);

namespace Drupal\scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
 * @since 1.0.0-rc1
 * @stability experimental
 */
class DismissRebuildNoticeController extends ControllerBase {

  /**
   * The user data service.
   *
   * @var \Drupal\user\UserDataInterface
   */
  protected UserDataInterface $userData;

  /**
   * {@inheritdoc}
   */
  public function __construct(UserDataInterface $userData) {
    $this->userData = $userData;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('user.data'),
    );
  }

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
        $this->userData->set('scolta', $this->currentUser()->id(), 'dismissed_rebuild_notice', $notice_id);
      }
    }

    // Only allow local single-slash paths: '//evil.com' is protocol-relative
    // and would redirect off-site, so it must not pass the leading-'/' check.
    $destination = (string) $request->query->get('destination', '');
    if ($destination !== '' && str_starts_with($destination, '/') && !str_starts_with($destination, '//')) {
      try {
        return new LocalRedirectResponse(Url::fromUserInput($destination)->toString());
      }
      catch (\InvalidArgumentException $e) {
        // Malformed destination — fall through to the settings page.
      }
    }

    // scolta.index_settings, not scolta.settings: the latter is scolta_ui's
    // route, and a site that builds an index without rendering search does not
    // have it — Url::fromRoute() would throw RouteNotFoundException and turn
    // dismissing a notice into a 500. The rebuild notice is the backend's, so
    // its fallback destination is the backend's screen.
    return new LocalRedirectResponse(Url::fromRoute('scolta.index_settings')->toString());
  }

}
