<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Menu\LocalTask;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a local task for canvas content templates on entity canonical pages.
 *
 * This plugin dynamically generates route parameters (entity_type, bundle,
 * view_mode) based on the current entity being viewed.
 */
class ContentTemplateLocalTask extends LocalTaskDefault implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new ContentTemplateLocalTask.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters(RouteMatchInterface $route_match): array {
    $parameters = parent::getRouteParameters($route_match);

    // Extract the entity type ID from the derivative ID (e.g., 'canvas_template_node' -> 'node').
    $derivative_id = $this->getDerivativeId();
    $entity_type_id = str_replace('canvas_template_', '', $derivative_id);

    // Get the entity from the current route using the entity type ID.
    $entity = $route_match->getParameter($entity_type_id);

    if ($entity instanceof ContentEntityInterface) {
      $parameters['entity_type'] = $entity_type_id;
      $parameters['bundle'] = $entity->bundle();
      // Default to 'full' view mode.
      // This needs to be adjusted if other view modes are supported.
      $parameters['view_mode_name'] = 'full';
    }

    return $parameters;
  }

}

