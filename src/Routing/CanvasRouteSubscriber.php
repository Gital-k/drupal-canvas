<?php

namespace Drupal\canvas\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the routing events.
 */
class CanvasRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   * Defines the 'canvas.content_template.edit' route dynamically.
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('entity.node.canonical')) {
      // Define the dynamic route for the template editor.
      $canvas_edit_route = new \Symfony\Component\Routing\Route(
        '/canvas/template/node/{node_type}/{view_mode}/{node_id}',
        [
          '_controller' => '\Drupal\node\Controller\NodeViewController::view',
          '_title_callback' => '\Drupal\node\Controller\NodeViewController::title',
          '_title_arguments' => ['@node' => 'Edit Content Template'],
        ],
        [
          'node' => '\d+',
        ],
      );

      $collection->add('canvas.content_template.edit', $canvas_edit_route);
    }
  }

}
