<?php

namespace Drupal\canvas_ai;

/**
 * Placement request data the AI model needs to see in the tool result.
 *
 * Carries the component structure with backend-assigned UUIDs and the
 * predicted post-placement layout, so the model can reference placed
 * components in follow-up calls.
 *
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents
 */
final class CanvasAiPlacementDto {

  /**
   * The AI-provided component structure with backend-assigned UUIDs.
   */
  private array $componentStructureWithUuids = [];

  /**
   * The predicted post-placement layout, as a region-keyed UUID tree.
   */
  private array $predictedLayout = [];

  /**
   * Stores the component structure with assigned UUIDs.
   *
   * @param array $structure
   *   The parsed page builder output after UUID assignment.
   */
  public function setComponentStructureWithUuids(array $structure): void {
    $this->componentStructureWithUuids = $structure;
  }

  /**
   * Gets the component structure with assigned UUIDs.
   *
   * @return array
   *   The parsed page builder output after UUID assignment.
   */
  public function getComponentStructureWithUuids(): array {
    return $this->componentStructureWithUuids;
  }

  /**
   * Stores the predicted post-placement layout.
   *
   * @param array $layout
   *   The region-keyed UUID tree.
   */
  public function setPredictedLayout(array $layout): void {
    $this->predictedLayout = $layout;
  }

  /**
   * Gets the predicted post-placement layout.
   *
   * @return array
   *   The region-keyed UUID tree.
   */
  public function getPredictedLayout(): array {
    return $this->predictedLayout;
  }

}
