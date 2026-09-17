<?php

declare(strict_types=1);

namespace Drupal\canvas\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Determines the theme to be used for specific Drupal Canvas (Canvas) routes.
 *
 * This theme negotiator uses the `canvas_stark` theme for Drupal Canvas routes
 * serving forms that are intended to be rendered using React, to guarantee
 * predictable markup. Otherwise the Redux integration is likely to break.
 *
 * This also achieves an intentional side effect: nothing of Drupal themes
 * is visible in the component instance form or entity fields forms displayed in
 * Drupal Canvas: `stark` defines no templates, and hence relies on all
 * default templates only.
 *
 * Finally, it keeps the Canvas UI itself in the site's front end theme. Those
 * routes are admin routes, so Drupal Core's admin theme negotiator would
 * otherwise render them using the admin theme.
 *
 * @see ui/src/components/form/twig-to-jsx-component-map.js
 * @see ui/src/components/form/withRHF.tsx
 */
final class CanvasThemeNegotiator implements ThemeNegotiatorInterface {

  use StringTranslationTrait;

  /**
   * The routes serving the Canvas UI itself, as opposed to its HTTP API.
   *
   * These are admin routes, but they must not use the admin theme: the Canvas
   * UI derives the global assets for its component preview <iframe>s from the
   * active theme, and those previews must look like the site's front end.
   *
   * @see \Drupal\canvas\Controller\CanvasController
   * @see \Drupal\user\Theme\AdminNegotiator
   * @see canvas.routing.yml
   */
  private const CANVAS_UI_ROUTES = [
    'canvas.boot.app',
    'canvas.boot.empty',
    'canvas.boot.entity',
  ];

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    $route_name = $route_match->getRouteName() ?? '';
    $use_admin_theme = (bool) $this->requestStack->getCurrentRequest()?->query->has('use_admin_theme');
    $use_canvas_stark = str_starts_with($route_name, 'canvas.api.form.');
    return $use_canvas_stark || $use_admin_theme || \in_array($route_name, self::CANVAS_UI_ROUTES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match) {
    $triggering_element_value = $this->requestStack->getCurrentRequest()?->request->get('_triggering_element_value');
    $still_in_media_library = $triggering_element_value !== (string) $this->t('Insert selected');

    if ($this->requestStack->getCurrentRequest()?->query->has('use_admin_theme') && $still_in_media_library) {
      // If the admin theme is not configured, use the default theme.
      $theme_config = $this->configFactory->get('system.theme');
      $admin = (string) $theme_config->get('admin');
      $admin_theme_name = $admin !== '' ? $admin : $theme_config->get('default');
      return $admin_theme_name;
    }
    $this->requestStack->getCurrentRequest()?->query->remove('use_admin_theme');
    if (\in_array($route_match->getRouteName(), self::CANVAS_UI_ROUTES, TRUE)) {
      return $this->configFactory->get('system.theme')->get('default');
    }
    return 'canvas_stark';
  }

}
