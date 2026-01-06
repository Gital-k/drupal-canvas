<?php

declare(strict_types=1);

namespace Drupal\canvas_ai;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;
use Drupal\canvas\ShapeMatcher\PropSourceSuggester;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Symfony\Component\Yaml\Yaml;

/**
 * Helper service for Canvas AI content template operations.
 */
class CanvasAiContentTemplateHelper {

  /**
   * Stored linkable fields with expressions.
   *
   * @var array
   */
  private array $linkableFieldsWithExpressions = [];

  /**
   * Constructs a CanvasAiContentTemplateHelper object.
   *
   * @param \Drupal\canvas\ShapeMatcher\PropSourceSuggester $propSourceSuggester
   *   The prop source suggester service.
   * @param \Drupal\canvas_ai\CanvasAiPageBuilderHelper $pageBuilderHelper
   *   The Canvas AI page builder helper service.
   */
  public function __construct(
    private readonly PropSourceSuggester $propSourceSuggester,
    private readonly CanvasAiPageBuilderHelper $pageBuilderHelper,
  ) {}

  /**
   * Gets field linking suggestions for a component.
   *
   * Returns which component props can be linked to which entity fields along
   * with the expression strings needed for programmatic linking.
   *
   * @param string $sdc_id
   *   The component config entity ID (e.g., 'space_heading').
   * @param string $entity_type
   *   The entity type ID (e.g., 'node').
   * @param string $bundle
   *   The entity bundle (e.g., 'article').
   *
   * @return array
   *   An associative array of field suggestions keyed by prop name.
   *   Each value contains an array of suggestion objects with 'label' and
   *   'expression' keys. Returns an empty array if no suggestions found.
   *
   * @throws \Exception
   *   If the component doesn't exist or doesn't have a valid component source.
   */
  public function getFieldSuggestions(string $sdc_id, string $entity_type, string $bundle, bool $filter_expressions = FALSE): array {
    // Load the component.
    $component = Component::load($sdc_id);
    if (!$component) {
      throw new \Exception("Component with ID '$sdc_id' does not exist.");
    }

    // Get component source.
    $component_source = $component->getComponentSource();
    if (!$component_source instanceof GeneratedFieldExplicitInputUxComponentSourceBase) {
      // Currently, only GeneratedFieldExplicitInputUxComponentSourceBase
      // supports field linking suggestions.
      return [];
    }

    // Get suggestions from PropSourceSuggester.
    $suggestions = $this->propSourceSuggester->suggest(
      $component_source->getSourceSpecificComponentId(),
      $component_source->getMetadata(),
      EntityDataDefinition::createFromDataType("entity:$entity_type:$bundle"),
    );

    // Format suggestions for AI consumption.
    $formatted_suggestions = [];
    foreach ($suggestions as $prop_name => $prop_data) {
      $prop_suggestions = [];

      // Get all dynamic prop sources.
      if (!empty($prop_data['instances'])) {
        foreach ($prop_data['instances'] as $field_in_target_entity => $dynamic_prop_source) {
          // Explicity skip revision-related fields.
          if (str_contains(strtolower($field_in_target_entity), 'revision')) {
            continue;
          }
          $expression_string = (string) $dynamic_prop_source->expression;
          if ($filter_expressions) {
            $prop_suggestions = array_merge($prop_suggestions, [$field_in_target_entity]);
          }
          else {
            $prop_suggestions[] = [
              'label' => $field_in_target_entity,
              'expression' => $expression_string,
            ];
          }
        }
      }

      if (!empty($prop_suggestions)) {
        $prop_name = ComponentPropExpression::fromString($prop_name)->propName;
        $formatted_suggestions[$prop_name] = $prop_suggestions;
      }
    }

    return $formatted_suggestions;
  }

  /**
   * Gets linkable content type fields with their expressions.
   *
   * Returns an associative array of field labels mapped to their expression
   * strings. Uses internal storage to efficiently retrieve when called
   * multiple times.
   *
   * @param string $entity_type
   *   The entity type ID (e.g., 'node').
   * @param string $bundle
   *   The entity bundle (e.g., 'article').
   *
   * @return array
   *   Associative array with field labels as keys and expressions as values.
   */
  private function getLinkableContentTypeFieldsWithExpressions(string $entity_type, string $bundle): array {
    // Check if already stored.
    if (!empty($this->linkableFieldsWithExpressions)) {
      return $this->linkableFieldsWithExpressions;
    }

    // Get all components as YAML.
    $components_yaml = $this->pageBuilderHelper->getComponentContextForAi();

    // Parse YAML to array.
    $components = Yaml::parse($components_yaml);

    // Track all unique linkable field labels with their expressions.
    $linkable_fields = [];

    // Process each component.
    foreach ($components as $component_id => $component_data) {
      // Get field suggestions for this component.
      try {
        $suggestions = $this->getFieldSuggestions(
          $component_id,
          $entity_type,
          $bundle
        );

        // Extract labels and expressions.
        foreach ($suggestions as $prop_suggestions) {
          if (is_array($prop_suggestions)) {
            foreach ($prop_suggestions as $suggestion) {
              if (isset($suggestion['label']) && isset($suggestion['expression'])) {
                $label = $suggestion['label'];
                $expression = $suggestion['expression'];
                // Store the expression for this label.
                $linkable_fields[$label] = $expression;
              }
            }
          }
        }
      }
      catch (\Exception) {
        // If we can't get suggestions for this component, skip it.
        continue;
      }
    }

    // Store for future calls.
    $this->linkableFieldsWithExpressions = $linkable_fields;

    return $linkable_fields;
  }

  /**
   * Gets all unique linkable field labels for an entity type and bundle.
   *
   * Returns all unique field labels that can be linked to component props,
   * sorted alphabetically.
   *
   * @param string $entity_type
   *   The entity type ID (e.g., 'node').
   * @param string $bundle
   *   The entity bundle (e.g., 'article').
   *
   * @return array
   *   Array of unique field labels sorted alphabetically.
   */
  public function getLinkableFields(string $entity_type, string $bundle): array {
    $fields_with_expressions = $this->getLinkableContentTypeFieldsWithExpressions($entity_type, $bundle);
    ksort($fields_with_expressions);
    return $fields_with_expressions;
  }

  /**
   * Gets the expression for a field label.
   *
   * @param string $entity_type
   *   The entity type ID (e.g., 'node').
   * @param string $bundle
   *   The entity bundle (e.g., 'article').
   * @param string $label
   *   The field label to get the expression for.
   *
   * @return string
   *   The expression string for the field label.
   *
   * @throws \Exception
   *   If the label is not a valid linkable field.
   */
  public function getFieldExpressionFromLabel(string $entity_type, string $bundle, string $label): string {
    $fields_with_expressions = $this->getLinkableContentTypeFieldsWithExpressions($entity_type, $bundle);

    if (!isset($fields_with_expressions[$label])) {
      throw new \Exception("The label '$label' is not a valid linkable field for $entity_type:$bundle.");
    }

    return $fields_with_expressions[$label];
  }

}
