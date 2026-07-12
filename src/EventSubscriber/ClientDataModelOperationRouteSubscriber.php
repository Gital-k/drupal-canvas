<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\ClientDataModel\Operation\ArgumentResolver;
use Drupal\canvas\ClientDataModel\SupportedClientDataModelOperations;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use function Symfony\Component\String\s;

/**
 * Defines a route subscriber to add routes for layout operations.
 */
final class ClientDataModelOperationRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    $controller = \ltrim(ApiLayoutController::class, '\\');
    foreach (SupportedClientDataModelOperations::cases() as $operation) {
      $operation_class = $operation->getOperationClass();
      if ($operation_class === NULL) {
        // The operation is not implemented yet: no route.
        continue;
      }
      $route = new Route(
        \sprintf("/canvas/api/v0/layout/%s/{entity_type}/{entity}", $operation->value),
        [
          '_controller' => \sprintf('%s::mutateModel', $controller),
          '_title_callback' => \sprintf('%s::getLabel', $controller),
          // @see \Drupal\canvas\ClientDataModel\Operation\ArgumentResolver
          ArgumentResolver::OPERATION_CLASS => $operation_class,
        ],
        [
          '_canvas_authentication_required' => 'TRUE',
          '_canvas_component_tree_edit_access' => 'TRUE',
          '_entity_access' => 'entity.update',
          '_format' => 'json',
        ],
        [
          '_canvas_use_template_draft' => TRUE,
          'parameters' => [
            'entity' => [
              'type' => 'entity:{entity_type}',
            ],
          ],
        ],
        methods: ['PATCH']
      );
      $collection->add(\sprintf('canvas.api.layout.patch.%s', s($operation->value)->snake()), $route);
    }

  }

}
