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
   * Gets the less detailed component context for AI.
   *
   * Returns component context with only id, name, and description fields,
   * excluding props and slots data.
   *
   * @return string
   *   The component context as a YAML string.
   */
  public function getLessDetailedComponentContext(): string {
    $component_context = $this->pageBuilderHelper->getComponentContextForAi();
    $filtered_context = [];

    foreach ($component_context as $source_label => $components) {
      $filtered_context[$source_label] = [];
      foreach ($components as $component_id => $component_data) {
        // Keep only id, name, and description fields.
        $filtered_context[$source_label][$component_id] = [
          'name' => $component_data['name'] ?? '',
          'description' => $component_data['description'] ?? '',
        ];
      }
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
   */
  public function getDetailedMetadataOfComponents(array $component_ids): string {
    $component_context = $this->pageBuilderHelper->getComponentContextForAi();
    $detailed_metadata = [];

    // Loop through requested component IDs.
    foreach ($component_ids as $component_id) {
      // Check each source for the component.
      foreach ($component_context as $components) {
        if (isset($components[$component_id])) {
          // If the component descriptions are overriden with the Canvas AI Component
          // Description form, the 'detailed_description' field will be present. Use that
          // instead of the default 'description' field.
          if (isset($components[$component_id]['detailed_description'])) {
            $components[$component_id]['description'] = $components[$component_id]['detailed_description'];
            unset($components[$component_id]['detailed_description']);
          }
          // Add the full component data keyed by component ID.
          $detailed_metadata[$component_id] = $components[$component_id];
          // Component found, no need to check other sources.
          break;
        }
      }
    }

    return Yaml::dump($detailed_metadata, 4, 2);
  }

}
