<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\ContentTemplateRepository;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Defines an access checker for canvas content template entity.
 */
final class ViewModeAccessCheck implements AccessInterface {

  public function __construct(
    private readonly AccessInterface $inner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ContentTemplateRepository $contentTemplateRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account, string $view_mode_name = 'default', ?string $bundle = NULL): AccessResultInterface {
    $current_route_name = $route_match->getRouteName();
    // AccessInterface does not declare access(). Possibly will be fixed in
    // https://www.drupal.org/node/2266817.
    if ($current_route_name === NULL) {
      // @phpstan-ignore-next-line method.notFound
      return $this->inner->access($route, $route_match, $account, $view_mode_name, $bundle);
    }
    $view_mode_routes = \array_map(
      static fn($id) => "entity.entity_view_display.$id.view_mode",
      $this->contentTemplateRepository->getSupportedEntityTypeIds(),
    );
    if (!\in_array($current_route_name, $view_mode_routes, TRUE)) {
      // @phpstan-ignore-next-line method.notFound
      return $this->inner->access($route, $route_match, $account, $view_mode_name, $bundle);
    }

    $template = $this->loadFromRoute($view_mode_name, $route_match, $bundle);
    $access = AccessResult::neutral();
    if ($template) {
      // Add the ContentTemplate as a cacheable dependency so access rebuilds
      // when content templates are added or updated.
      $access = $access->addCacheableDependency($template);
      if ($template->status()) {
        return $access->allowedIfHasPermission($account, ContentTemplate::ADMIN_PERMISSION);
      }
    }
    // @phpstan-ignore-next-line method.notFound
    return $this->inner->access($route, $route_match, $account, $view_mode_name, $bundle);
  }

  /**
   * Loads a ContentTemplate from the current route context.
   *
   * Assumes the route carries an `entity_type_id` default and, for entity
   * types with bundles, a route parameter named after the bundle entity type —
   * the standard structure of Field UI entity view display routes.
   *
   * @param string $view_mode
   *   The view mode machine name.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param string|null $bundle
   *   The bundle machine name. If NULL, will be extracted from route.
   *
   * @return \Drupal\canvas\Entity\ContentTemplate|null
   *   The loaded ContentTemplate, or NULL if not found or the route structure
   *   doesn't match expectations.
   */
  private function loadFromRoute(string $view_mode, RouteMatchInterface $route_match, ?string $bundle = NULL): ?ContentTemplate {
    $entity_type_id = $route_match->getRouteObject()?->getDefault('entity_type_id');
    if (!$entity_type_id) {
      return NULL;
    }

    if ($bundle === NULL) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      if ($bundle_entity_type = $entity_type->getBundleEntityType()) {
        $bundle = $route_match->getRawParameter($bundle_entity_type);
      }
    }
    return $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID)->load("$entity_type_id.$bundle.$view_mode");
  }

}
