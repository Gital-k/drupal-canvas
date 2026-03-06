<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\Resource\CanvasResourceLink;
use Drupal\canvas\Resource\CanvasResourceLinkCollection;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Url;

/**
 * Provides a helper for building entity operation links with access checking.
 *
 * @internal
 */
trait EntityOperationLinksTrait {

  /**
   * Builds a collection of entity operation links based on access checks.
   *
   * For each operation, access is determined either via the entity operation
   * string (if 'op' is specified) or via URL-level access checking (otherwise).
   * Only operations that are allowed are included in the returned collection;
   * denied operations contribute their cacheability metadata.
   *
   * @param array<string, array{route: array{name: string, params?: array<string, string|null>}, op?: string}> $operations
   *   An associative array keyed by link relation type. Each value is
   *   an array with:
   *   - route: An array with 'name' (route name) and optional 'params' (route
   *     parameters).
   *   - op: (optional) An entity operation string (e.g. 'delete'). When
   *     present, access is checked via the entity's access() method. When
   *     absent, access is checked via the route URL.
   *
   * @return \Drupal\canvas\Resource\CanvasResourceLinkCollection
   *   A collection of links for allowed operations, with cacheability metadata
   *   from denied operations included.
   */
  protected function buildEntityOperationLinks(array $operations): CanvasResourceLinkCollection {
    $links = new CanvasResourceLinkCollection([]);
    foreach ($operations as $link_rel => $operation) {
      $route_name = $operation['route']['name'];
      $route_params = $operation['route']['params'] ?? [];
      $url = Url::fromRoute($route_name, $route_params);
      if (isset($operation['op'])) {
        $access = $this->access(operation: $operation['op'], return_as_object: TRUE);
      }
      else {
        $access = $url->access(return_as_object: TRUE);
      }
      \assert($access instanceof AccessResult);
      if ($access->isAllowed()) {
        $links = $links->withLink($link_rel, new CanvasResourceLink($access, $url, $link_rel));
      }
      else {
        $links->addCacheableDependency($access);
      }
    }
    return $links;
  }

}
