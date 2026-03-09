<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\canvas\ClientDataModel\Operation\DeleteNode;
use Drupal\canvas\ClientDataModel\Operation\DuplicateNode;
use Drupal\canvas\ClientDataModel\Operation\InsertNodes;
use Drupal\canvas\ClientDataModel\Operation\MoveNode;
use Drupal\canvas\ClientDataModel\Operation\ShiftNode;
use Drupal\canvas\ClientDataModel\Operation\UpdateModel;
use Drupal\canvas\Controller\ApiLayoutController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use function Symfony\Component\String\s;

/**
 * Defines a route subscriber to add routes for layout operations.
 */
final class ClientDataModelOperationRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    $controller = \ltrim(ApiLayoutController::class, '\\');
    $map = [
      'move-node' => MoveNode::class,
      'insert-nodes' => InsertNodes::class,
      'shift-node' => ShiftNode::class,
      'delete-node' => DeleteNode::class,
      'duplicate-node' => DuplicateNode::class,
      'update-model' => UpdateModel::class,
    ];
    foreach ($map as $slug => $operation_class) {
      $route = new Route(
        \sprintf("/canvas/api/v0/layout/%s/{entity_type}/{entity}", $slug),
        [
          '_controller' => \sprintf('%s::mutateModel', $controller),
          '_title_callback' => \sprintf('%s::getLabel', $controller),
          // @see \Drupal\canvas\Controller\ApiLayoutController::mutateModel
          'operation_class' => $operation_class,
        ],
        [
          '_canvas_component_tree_edit_access' => 'TRUE',
          '_entity_access' => 'entity.update',
        ],
        [
          '_canvas_use_template_draft' => TRUE,
          'parameters' => [
            'entity' => [
              'type' => 'entity:{entity_type}',
            ],
          ],
          '_format' => 'json',
        ],
        methods: ['PATCH']
      );
      $collection->add(\sprintf('canvas.api.layout.patch.%s', s($slug)->snake()), $route);
    }

  }

}
