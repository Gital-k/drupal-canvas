<?php

declare(strict_types=1);

namespace Drupal\canvas_tour\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Bridges core's Tour module onto the Canvas editor.
 *
 * \Drupal\canvas\Controller\CanvasController::__invoke() returns an
 * HtmlResponse directly instead of a render array, so the kernel never fires
 * kernel.view for it and the normal page render pipeline
 * (hook_page_top()/hook_page_bottom(), which is how Tour normally attaches
 * itself) never runs for any Canvas editor page.
 *
 * Every Canvas editor URL — including entity-specific ones like
 * `/canvas/editor/canvas_page/111` — is rewritten by
 * \Drupal\canvas\PathProcessor\CanvasPathProcessor to the bare `/canvas` path
 * before Drupal's router runs, so the kernel always resolves
 * `canvas.boot.empty` (never `canvas.boot.entity`). So there's no way to
 * scope a Tour to a specific entity type from PHP: a Tour entity targeting
 * `canvas.boot.empty` applies to the Canvas editor shell as a whole.
 *
 * \Drupal\Core\EventSubscriber\HtmlResponseSubscriber, however, processes the
 * attachments of *every* HtmlResponse on kernel.response, regardless of which
 * controller produced it. This subscriber runs ahead of it (priority 10,
 * versus HtmlResponseSubscriber's default priority 0 and
 * \Drupal\Core\EventSubscriber\HtmlResponsePlaceholderStrategySubscriber's
 * priority 5) to merge in Tour's own attachments before those core
 * subscribers bake attachments into the response body. This requires
 * #3592107 (a `scripts_bottom` placeholder in CanvasController::HTML) so
 * that footer-scope JS libraries (Tour's own `tour/tour` included) actually
 * reach the page instead of being silently dropped.
 *
 * Core's own Tour trigger is rendered by the Toolbar module, which Canvas's
 * editor shell never builds. `js/canvas-tour-bridge.js` inserts an
 * equivalent trigger into the Canvas topbar and calls `Drupal.tour.setActive()`
 * — core Tour's own public toggle — so the tour this subscriber attaches is
 * actually reachable.
 *
 * @see \Drupal\canvas\Controller\CanvasController::__invoke()
 * @see \Drupal\canvas\PathProcessor\CanvasPathProcessor
 * @see \Drupal\Core\EventSubscriber\HtmlResponseSubscriber
 * @see \Drupal\tour\Hook\TourHooks::pageBottom()
 * @see js/canvas-tour-bridge.js
 */
final class TourSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::RESPONSE][] = ['onResponse', 10];
    return $events;
  }

  /**
   * Merges Tour attachments into the Canvas editor's HtmlResponse.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$response instanceof HtmlResponse) {
      return;
    }

    if ($event->getRequest()->attributes->get('_route') !== 'canvas.boot.empty') {
      return;
    }

    // Whether Tour attachments end up on the response depends on the
    // permission check below, so any cache layer keying off this response's
    // cacheable metadata (e.g. Dynamic Page Cache) must vary by it too —
    // applied ahead of the permission check, so the early-return path is
    // covered as well.
    $response->addCacheableDependency((new CacheableMetadata())->addCacheContexts(['user.permissions']));

    // Mirror \Drupal\tour\Hook\TourHooks::pageBottom(), which gates Tour's
    // own attachment to every normal page behind this same permission.
    // Canvas's own access check (_canvas_ui_access) governs who can reach the
    // editor at all, but says nothing about Tour access specifically.
    if (!$this->currentUser->hasPermission('access tour')) {
      return;
    }

    $tour_storage = $this->entityTypeManager->getStorage('tour');
    $ids = $tour_storage->getQuery()
      ->condition('routes.*.route_name', 'canvas.boot.empty')
      ->condition('status', TRUE)
      ->accessCheck(FALSE)
      ->execute();
    if (empty($ids)) {
      return;
    }

    $build = $this->entityTypeManager->getViewBuilder('tour')->viewMultiple($tour_storage->loadMultiple($ids));
    if (empty($build['#attached']['library'])) {
      return;
    }

    $response->addCacheableDependency(CacheableMetadata::createFromRenderArray($build));

    $attachments = $response->getAttachments();
    $attachments['library'] = [...$attachments['library'] ?? [], ...$build['#attached']['library'], 'canvas_tour/canvas-tour-bridge'];
    $attachments['drupalSettings'] = array_merge($attachments['drupalSettings'] ?? [], $build['#attached']['drupalSettings'] ?? []);
    $response->setAttachments($attachments);
  }

}
