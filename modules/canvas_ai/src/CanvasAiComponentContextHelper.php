<?php

namespace Drupal\canvas_ai;

use Symfony\Component\Yaml\Yaml;

/**
 * Provides helper methods for component context.
 */
class CanvasAiComponentContextHelper {

  /**
   * Constructor.
   *
   * @param \Drupal\canvas_ai\CanvasAiPageBuilderHelper $pageBuilderHelper
   *   The Canvas AI page builder helper service.
   */
  public function __construct(
    private readonly CanvasAiPageBuilderHelper $pageBuilderHelper,
  ) {
  }

  /**
   * Gets available component data with only name and description.
   *
   * Returns component context with only name and description fields,
   * excluding props and slots data. The returned YAML string contains
   * component IDs as keys with their name and description values.
   *
   * @return string
   *   The component context as a YAML string.
   */
  public function getAvailableComponentDataWithOnlyNameAndDescription(): string {
    $component_context = $this->pageBuilderHelper->getComponentContextForAi();
    $filtered_context = [];
    foreach ($component_context as $component_id => $component_data) {
      // Keep only id, name, and description fields.
      $filtered_context[$component_id] = [
        'name' => $component_data['name'] ?? '',
        'description' => $component_data['description'] ?? '',
      ];
    }

    return Yaml::dump($filtered_context, 4, 2);
  }

  /**
   * Gets detailed metadata for specific components.
   *
   * Returns full component data for the specified component IDs containing
   * data of props and slots.
   *
   * @param array $component_ids
   *   Array of component IDs to retrieve.
   *
   * @return string
   *   The component metadata as a YAML string, keyed by component ID.
   *
   * @throws \InvalidArgumentException
   *   Thrown when a requested component ID is not present in the component
   *   context.
   */
  public function getDetailedMetadataOfComponents(array $component_ids): string {
    $component_context = $this->pageBuilderHelper->getComponentContextForAi();
    $detailed_metadata = [];
    $missing_component_ids = array_diff($component_ids, array_keys($component_context));

    if ($missing_component_ids !== []) {
      throw new \InvalidArgumentException(strtr('Component with id @id not found', ['@id' => implode(', ', $missing_component_ids)]));
    }

    // Loop through requested component IDs.
    foreach ($component_ids as $component_id) {
      // If the component descriptions are overriden with the Canvas AI Component
      // Description form, the 'detailed_description' field will be present. Use that
      // instead of the default 'description' field.
      if (isset($component_context[$component_id]['detailed_description'])) {
        $component_context[$component_id]['description'] = $component_context[$component_id]['detailed_description'];
        unset($component_context[$component_id]['detailed_description']);
      }

      // Add the full component data keyed by component ID.
      $detailed_metadata[$component_id] = $component_context[$component_id];
    }

    return Yaml::dump($detailed_metadata, 4, 2);
  }

}
