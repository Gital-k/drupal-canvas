<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects the field UI base route to the Manage Fields page.
 *
 * @internal
 */
final class CanvasPageFieldUiController extends ControllerBase {

  public function __construct(
    private readonly RouteProviderInterface $routeProvider,
  ) {
  }

  /**
   * Redirects to Manage Fields if Field UI is enabled.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|array
   *   A redirect response or a render array.
   */
  public function overview(): RedirectResponse|array {
    $routes = $this->routeProvider->getRoutesByNames(['entity.canvas_page.field_ui_fields']);
    if (!empty($routes)) {
      $url = Url::fromRoute('entity.canvas_page.field_ui_fields')->toString();
      return new RedirectResponse($url);
    }

    return [
      '#markup' => $this->t('Enable the Field UI module to manage fields for Canvas pages.'),
    ];
  }

}
