<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Builds the page and site context returned with routed Canvas content.
 *
 * The context carries the same data Drupal-rendered Code Components read
 * through `usePageContext()` and `useSiteContext()`, generated for the routed
 * request so language, access, cacheability, and draft-preview semantics are
 * Drupal's. Headless frontends receive empty theme assets: the frontend owns
 * its own assets, and empty URLs are valid site context.
 *
 * @see \Drupal\canvas\CodeComponentDataProvider
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */
final class CanvasContentContextBuilder {

  public function __construct(
    private readonly CodeComponentDataProvider $codeComponentDataProvider,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the context for the current routed request.
   *
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   Receives the cacheability of the generated data.
   *
   * @return array{page: array{pageTitle: string, breadcrumbs: array, mainEntity: array|null}, site: array{branding: array, baseUrl: string, themeAssets: array}}
   *   The context, shaped like `CanvasContext` in the `drupal-canvas` package.
   */
  public function build(RefinableCacheableDependencyInterface $cacheability): array {
    $provider = $this->codeComponentDataProvider;
    $v0 = CodeComponentDataProvider::V0;

    $page_title = $provider->getCanvasDataPageTitleV0()[$v0]['pageTitle'];
    $breadcrumbs = $provider->getCanvasDataBreadcrumbsV0($cacheability)[$v0]['breadcrumbs'];
    $main_entity = $provider->getCanvasDataMainEntityV0($cacheability)[$v0]['mainEntity'] ?? NULL;
    // The translation list, when present, depends on the enabled languages
    // and the negotiated content language.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
    if ($main_entity !== NULL) {
      $cacheability->addCacheTags(['config:configurable_language_list', 'config:language.negotiation']);
      $cacheability->addCacheContexts(['languages:language_content']);
    }

    $branding = $provider->getCanvasDataBrandingV0()[$v0]['branding'];
    $base_url = $provider->getCanvasDataBaseUrlV0()[$v0]['baseUrl'];
    $cacheability->addCacheableDependency($this->configFactory->get('system.site'));
    $cacheability->addCacheableDependency(
      (new CacheableMetadata())->addCacheContexts(['url.site', 'route']),
    );

    return [
      'page' => [
        'pageTitle' => (string) $page_title,
        'breadcrumbs' => $breadcrumbs,
        'mainEntity' => $main_entity,
      ],
      'site' => [
        'branding' => $branding,
        'baseUrl' => $base_url,
        'themeAssets' => [
          'logo' => ['url' => ''],
          'favicon' => ['url' => '', 'mimeType' => ''],
        ],
      ],
    ];
  }

}
