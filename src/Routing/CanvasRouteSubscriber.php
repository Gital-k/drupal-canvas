<?php

namespace Drupal\canvas\Routing;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the routing events.
 */
class CanvasRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   *
   * Defines the 'canvas.content_template.edit' route dynamically.
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($collection->get('entity.node.canonical')) {
      // Define the dynamic route for the template editor.
      $canvas_edit_route = new Route(
        '/canvas/template/node/{node_type}/{view_mode}/{node}',
        [
          '_controller' => '\Drupal\canvas\Controller\CanvasTemplateEditorController::view',
          '_title_callback' => '\Drupal\canvas\Controller\CanvasTemplateEditorController::title',
        ],
        [
          '_permission' => ContentTemplate::ADMIN_PERMISSION,
          '_entity_access' => 'node.view',
        ],
        [
          'parameters' => [
            'node' => [
              'type' => 'entity:node',
            ],
          ],
        ]
      );

      $collection->add('canvas.content_template.edit', $canvas_edit_route);
    }
  }

}
