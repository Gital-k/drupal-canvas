<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Menu\LocalTask;

use Drupal\canvas\Entity\ContentTemplate;
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
   * Cached content template for the current route.
   *
   * @var \Drupal\canvas\Entity\ContentTemplate|null|false
   */
  protected ContentTemplate|null|false $contentTemplate = FALSE;

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

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $tags = parent::getCacheTags();

    // Add the content template as a cache dependency so the local task
    // is rebuilt when the template is created, updated, or deleted.
    $content_template = $this->getContentTemplate();
    if ($content_template) {
      $tags = array_merge($tags, $content_template->getCacheTags());
    }
    else {
      // If no template exists yet, add the content_template_list tag so
      // the local task is rebuilt when a new template is created.
      $tags[] = 'content_template_list';
    }

    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    $contexts = parent::getCacheContexts();

    // Add URL context since the local task depends on the entity in the route.
    $contexts[] = 'url';

    return array_unique($contexts);
  }

  /**
   * Gets the content template for the current route.
   *
   * @return \Drupal\canvas\Entity\ContentTemplate|null
   *   The content template, or NULL if not found.
   */
  protected function getContentTemplate(): ?ContentTemplate {
    if ($this->contentTemplate !== FALSE) {
      return $this->contentTemplate;
    }
    $route_match = \Drupal::routeMatch();

    // Extract the entity type ID from the derivative ID.
    $derivative_id = $this->getDerivativeId();
    $entity_type_id = str_replace('canvas_template_', '', $derivative_id);

    $entity = $route_match->getParameter($entity_type_id);

    if (!$entity instanceof ContentEntityInterface) {
      $this->contentTemplate = NULL;
      return NULL;
    }

    // Load and cache the content template for this entity.
    // Using 'full' as the default view mode. This needs to be
    // adjusted if other view modes are supported.
    $this->contentTemplate = ContentTemplate::loadForEntity($entity, 'full');
    return $this->contentTemplate;
  }

}

