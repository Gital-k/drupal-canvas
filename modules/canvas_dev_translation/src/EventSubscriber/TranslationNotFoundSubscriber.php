<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_translation\EventSubscriber;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Returns 404 when canvas_page has no translation for the negotiated language.
 *
 * Applies to:
 * - entity.canvas_page.canonical (frontend visitors)
 * - canvas.boot.entity (Canvas UI editor, canvas_page only)
 */
class TranslationNotFoundSubscriber implements EventSubscriberInterface {

  /**
   * Routes on which to enforce translation 404.
   *
   * Maps route name to entity parameter name.
   */
  private const PROTECTED_ROUTES = [
    'entity.canvas_page.canonical' => 'canvas_page',
    'canvas.boot.entity'           => 'entity',
  ];

  /**
   * Constructs a TranslationNotFoundSubscriber object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly LanguageManagerInterface $languageManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Throws a 404 if the negotiated language has no translation for the entity.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The kernel request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $route_name = $this->routeMatch->getRouteName();
    if (!isset(self::PROTECTED_ROUTES[$route_name])) {
      return;
    }

    // For canvas.boot.entity, only apply to canvas_page entities.
    if ($route_name === 'canvas.boot.entity') {
      $entity_type = $this->routeMatch->getParameter('entity_type');
      if ($entity_type !== 'canvas_page') {
        return;
      }
    }

    $param_name = self::PROTECTED_ROUTES[$route_name];
    $entity = $this->routeMatch->getParameter($param_name);

    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    $language = $this->languageManager->getCurrentLanguage();
    $langcode = $language->getId();
    $default_langcode = $entity->getUntranslated()->language()->getId();

    // Only enforce 404 if:
    // - negotiated language is NOT default
    // - AND translation does not exist.
    if ($langcode !== $default_langcode && !$entity->hasTranslation($langcode)) {

      // Different messages for editors vs visitors.
      $is_editor = $route_name === 'canvas.boot.entity'
        || $this->currentUser->hasPermission('edit canvas_page');

      $message = $is_editor
        ? sprintf(
            "No translation exists for '%s' in language '%s'. Create one via the translations panel.",
            $entity->label(),
            $langcode
          )
        : 'Page not found.';
      throw new NotFoundHttpException($message);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 20 — after routing (0) but before controllers (default).
    return [
      KernelEvents::REQUEST => ['onRequest', 20],
    ];
  }

}
