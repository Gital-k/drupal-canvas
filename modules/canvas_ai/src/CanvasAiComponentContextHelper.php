<?php

declare(strict_types=1);

namespace Drupal\canvas_ai;

use Symfony\Component\Yaml\Yaml;

/**
 * Builds the component catalog and per-component metadata for AI agents.
 *
 * Splits the component context into two views so agents spend tokens in
 * proportion to the components they actually use:
 * - a lightweight catalog (id, name, description) of every component, for
 *   scanning candidates;
 * - detailed metadata (props and slots) for a named subset, fetched on demand.
 *
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetComponentContext
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetMetadataOfComponents
 */
final class CanvasAiComponentContextHelper {

  public function __construct(
    private readonly CanvasAiPageBuilderHelper $pageBuilderHelper,
  ) {
  }

  /**
   * Gets the lightweight component catalog for AI.
   *
   * Returns every component's name and description (keyed by id), dropping
   * props and slots so agents can scan candidates cheaply.
   *
   * @return string
   *   The component catalog as a YAML string.
   */
  public function getComponentCatalog(): string {
    $catalog = [];
    foreach ($this->pageBuilderHelper->getComponentContextForAi() as $component_id => $component_data) {
      $catalog[$component_id] = [
        'name' => $component_data['name'] ?? '',
        'description' => $component_data['description'] ?? '',
      ];
    }
    return Yaml::dump($catalog, 4, 2);
  }

  /**
   * Gets the props and slots for the given components.
   *
   * @param array $component_ids
   *   The component ids to retrieve. Unknown ids are skipped.
   *
   * @return string
   *   The component metadata as a YAML string, keyed by component id.
   */
  public function getComponentDetails(array $component_ids): string {
    $context = $this->pageBuilderHelper->getComponentContextForAi();
    $metadata = [];
    foreach ($component_ids as $component_id) {
      if (isset($context[$component_id])) {
        $metadata[$component_id] = $context[$component_id];
      }
    }
    return Yaml::dump($metadata, 4, 2);
  }

}
