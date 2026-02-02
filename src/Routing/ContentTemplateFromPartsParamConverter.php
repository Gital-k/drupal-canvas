<?php

declare(strict_types=1);

namespace Drupal\canvas\Routing;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Symfony\Component\Routing\Route;

/**
 * Converts content template parts to a ContentTemplate entity.
 *
 * This converter constructs a ContentTemplate entity ID from separate route
 * parameters (entity_type, bundle, view_mode_name) and loads the entity.
 */
final readonly class ContentTemplateFromPartsParamConverter implements ParamConverterInterface {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    // Extract the parts from the route defaults.
    $entity_type = $defaults['entity_type'] ?? NULL;
    $bundle = $defaults['bundle'] ?? NULL;
    $view_mode_name = $defaults['view_mode_name'] ?? NULL;

    if (!$entity_type || !$bundle || !$view_mode_name) {
      return NULL;
    }
    $template_id = "$entity_type.$bundle.$view_mode_name";

    $storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    return $storage->load($template_id);
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return isset($definition['type']) && $definition['type'] === 'canvas_content_template_from_parts';
  }

}
