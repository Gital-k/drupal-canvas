<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves the site's JSON:API settings to unauthenticated headless apps.
 *
 * The headless SDK needs the JSON:API prefix before any authentication exists
 * (public content mode), so it can target sites serving JSON:API from a
 * non-default prefix (e.g. `/api`). The prefix is not sensitive: it is already
 * present in `drupalSettings` on every rendered page. Only the JSON:API
 * settings are exposed here; the editor-only site-data endpoint carries the
 * rest of the canvasData.v0 payload.
 *
 * @see \Drupal\canvas\Controller\SiteDataController
 */
final class JsonApiSettingsController implements ContainerInjectionInterface {

  public function __construct(
    private readonly CodeComponentDataProvider $codeComponentDataProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(CodeComponentDataProvider::class),
    );
  }

  /**
   * Returns the JSON:API settings portion of the canvasData.v0 payload.
   */
  public function get(): CacheableJsonResponse {
    $data = $this->codeComponentDataProvider->getCanvasDataJsonApiSettingsV0();
    // NULL when JSON:API is not installed on the site; the headless SDK then
    // leaves its client's default prefix in place.
    $settings = $data[CodeComponentDataProvider::V0]['jsonapiSettings'];
    $cacheability = new CacheableMetadata();
    // The prefix is stored in jsonapi_extras config (written by its settings
    // form) and surfaced via the jsonapi.base_path container parameter, which
    // emits no cache tag on its own. Depending on the jsonapi_extras config
    // invalidates this response when the prefix is changed through the UI.
    // No url.site context: the prefix is a relative path fragment with no
    // URL-dependent data, so the response never varies by site URL.
    $cacheability->addCacheTags(['config:jsonapi_extras.settings']);
    $response = new CacheableJsonResponse($settings);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
