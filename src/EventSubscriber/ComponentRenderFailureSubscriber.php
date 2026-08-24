<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers a partially failed render with a server error instead of "200 OK".
 *
 * A component instance that crashes while rendering is contained: the rest of
 * the component tree still renders, and the crashed instance is replaced by a
 * fallback. That containment is deliberate — one broken component must not take
 * a whole page down.
 *
 * What must not survive containment is the *status* of the response. A page
 * that failed to render part of itself is not "200 OK":
 * - it is stored by every cache in front of it — Drupal's page caches, a
 *   reverse proxy, a CDN — and served to everyone until something invalidates
 *   it, long after the condition that caused the crash has passed;
 * - it is invisible to monitoring, which is exactly how a broken page stays
 *   broken for days.
 *
 * Previews are exempt: the Canvas UI renders broken components on purpose, and
 * those responses must stay successful.
 *
 * @see \Drupal\canvas\Element\RenderSafeComponentContainer::handleComponentException()
 */
final class ComponentRenderFailureSubscriber implements EventSubscriberInterface {

  /**
   * Request attribute set when a non-preview component render crashed.
   */
  public const string REQUEST_ATTRIBUTE = '_canvas_component_render_failed';

  /**
   * Turns a response containing a crashed component into a server error.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   */
  public static function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    if ($event->getRequest()->attributes->get(self::REQUEST_ATTRIBUTE) !== TRUE) {
      return;
    }
    $response = $event->getResponse();
    // Only a response that claims success is wrong here. Anything else (a
    // redirect, a 403/404 built by an exception subscriber, a response another
    // subscriber already marked as failed) is left alone.
    if ($response->getStatusCode() !== Response::HTTP_OK) {
      return;
    }
    $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
    // Prevent the failed render from being cached, by Drupal or by anything in
    // front of it: max-age 0 makes FinishResponseSubscriber emit no-cache, and
    // both Page Cache and Dynamic Page Cache refuse non-cacheable responses.
    // @see \Drupal\Core\EventSubscriber\FinishResponseSubscriber::onRespond()
    if ($response instanceof CacheableResponseInterface) {
      $response->getCacheableMetadata()->setCacheMaxAge(0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Run before Dynamic Page Cache (7) decides whether to store the response
      // and before FinishResponseSubscriber (0) derives its cache headers.
      // @see \Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber
      KernelEvents::RESPONSE => ['onResponse', 100],
    ];
  }

}
