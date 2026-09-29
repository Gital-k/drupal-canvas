<?php

declare(strict_types=1);

namespace Drupal\canvas_content_lock\EventSubscriber;

use Drupal\canvas\Entity\Page;
use Drupal\canvas_content_lock\ContentLockManager;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Attaches the content lock UI to the Canvas UI.
 *
 * The Canvas UI is a single-page app: every /canvas/* path boots through one
 * of the canvas.boot.* routes, and the app then navigates client-side. The
 * library is therefore attached to all of them; the JavaScript decides which
 * page, if any, to lock based on the current URL.
 *
 * @see \Drupal\canvas\Controller\CanvasController
 * @see \Drupal\canvas\PathProcessor\CanvasPathProcessor
 */
final class ContentLockAssetsSubscriber implements EventSubscriberInterface {

  private const array CANVAS_BOOT_ROUTES = [
    'canvas.boot.app',
    'canvas.boot.empty',
    'canvas.boot.entity',
  ];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Runs before HtmlResponseSubscriber (priority 0) processes attachments.
    // @see \Drupal\Core\EventSubscriber\HtmlResponseSubscriber
    return [
      KernelEvents::RESPONSE => ['onResponse', 100],
    ];
  }

  /**
   * Adds the content lock library and settings to the Canvas UI response.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $response = $event->getResponse();
    if (!$response instanceof HtmlResponse || !\in_array($this->routeMatch->getRouteName(), self::CANVAS_BOOT_ROUTES, TRUE)) {
      return;
    }

    $response->addAttachments([
      'library' => ['canvas_content_lock/content-lock'],
      'drupalSettings' => [
        'canvasContentLock' => [
          'entityType' => Page::ENTITY_TYPE_ID,
          'pollInterval' => ContentLockManager::POLL_INTERVAL,
          'canBreak' => $this->currentUser->hasPermission(ContentLockManager::BREAK_PERMISSION),
          'currentUserId' => (int) $this->currentUser->id(),
        ],
      ],
    ]);
    $response->addCacheableDependency((new CacheableMetadata())->addCacheContexts(['user']));
  }

}
