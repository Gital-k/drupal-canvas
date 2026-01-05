<?php

namespace Drupal\canvas_ai;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Validation\BasicRecursiveValidatorFactory;

/**
 * Service for validating AI-generated component structures.
 */
class AiResponseValidator {

  use ComponentTreeItemListInstantiatorTrait;
  use ConstraintPropertyPathTranslatorTrait;

  /**
   * Constructs a new AiResponseValidator.
   *
   * @param \Drupal\Core\Validation\BasicRecursiveValidatorFactory $validatorFactory
   *   The validator factory.
   * @param \Drupal\Component\Uuid\UuidInterface $uuidService
   *   The UUID service.
   * @param \Drupal\canvas_ai\CanvasAiContentTemplateHelper $contentTemplateHelper
   *   The Canvas AI content template helper service.
   * @param \Drupal\canvas_ai\CanvasAiPageBuilderHelper $pageBuilderHelper
   *   The Canvas AI page builder helper service.
   */
  public function __construct(
    protected readonly BasicRecursiveValidatorFactory $validatorFactory,
    protected readonly UuidInterface $uuidService,
    protected readonly CanvasAiContentTemplateHelper $contentTemplateHelper,
    protected readonly CanvasAiPageBuilderHelper $pageBuilderHelper,
  ) {
  }

  /**
   * Validates the component structure.
   *
   * @param array $componentGroups
   *   The component groups to validate.
   *
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   *   When validation fails.
   */
  public function validateComponentStructure(array $componentGroups): void {
    // Create a mapping of components to their original paths.
    $pathMapping = [];

    // Convert YAML structure to Canvas ComponentTreeItem format.
    $componentTreeData = $this->convertToComponentTreeData($componentGroups, NULL, NULL, 'components', $pathMapping);

    $componentTreeItemList = $this->createDanglingComponentTreeItemList();
    $componentTreeItemList->setValue($componentTreeData);
    $violations = $componentTreeItemList->validate();

    if ($violations->count() > 0) {
      throw new ConstraintViolationException(
        $this->translateConstraintPropertyPathsAndRoot(
          $this->buildPathTranslationMap($componentTreeData, $pathMapping),
          $violations,
          ''
        ),
        'Component validation errors'
      );
    }
  }

  /**
   * Converts component groups to component tree data.
   *
   * @param array $componentGroups
   *   The component groups to convert.
   * @param string|null $parentUuid
   *   The parent UUID, if any.
   * @param string|null $slotName
   *   The slot name, if any.
   * @param string $pathPrefix
   *   The path prefix for the current level.
   * @param array &$pathMapping
   *   Reference to path mapping array.
   *
   * @return array
   *   The converted component tree data.
   */
  private function convertToComponentTreeData(
    array $componentGroups,
    ?string $parentUuid = NULL,
    ?string $slotName = NULL,
    string $pathPrefix = 'components',
    array &$pathMapping = [],
  ): array {
    $componentTreeData = [];
    foreach ($componentGroups as $groupIndex => $componentGroup) {
      foreach ($componentGroup as $componentId => $componentData) {
        $componentUuid = $this->uuidService->generate();

        $componentPath = sprintf('%s.%d.[%s]', $pathPrefix, $groupIndex, $componentId);
        $pathMapping[$componentUuid] = $componentPath;

        // Create a temp version if the component does not exist to allow
        // validation to proceed. The constraints will flag invalid components
        // later.
        $component = Component::load($componentId);
        $componentVersion = $component ? $component->getActiveVersion() : "temp-version-$componentUuid";
        if ($component instanceof Component && !empty($componentData['props'])) {
          $source = $component->getComponentSource();
          $clientNormalized = $component->normalizeForClientSide()->values;
          $clientModel['source'] = $clientNormalized['propSources'];
          $clientModel['resolved'] = $componentData['props'];
          $inputs = $source->clientModelToInput($componentUuid, $component, $clientModel, NULL);
        }
        else {
          $inputs = [];
        }

        $componentTreeItem = [
          'uuid' => $componentUuid,
          'component_id' => $componentId,
          'component_version' => $componentVersion,
          'inputs' => $inputs,
        ];
        if ($parentUuid !== NULL) {
          $componentTreeItem['parent_uuid'] = $parentUuid;
          $componentTreeItem['slot'] = $slotName;
        }

        $componentTreeData[] = $componentTreeItem;

        // Process slots recursively.
        if (isset($componentData['slots']) && is_array($componentData['slots'])) {
          foreach ($componentData['slots'] as $slot => $slotComponentGroups) {
            $slotPath = sprintf('%s.slots.%s', $componentPath, $slot);
            $componentTreeData = array_merge(
              $componentTreeData,
              $this->convertToComponentTreeData(
                $slotComponentGroups,
                $componentUuid,
                $slot,
                $slotPath,
                $pathMapping
              )
            );
          }
        }
      }
    }
    return $componentTreeData;
  }

  /**
   * Builds the path translation map.
   *
   * @param array $componentTreeData
   *   The component tree data.
   * @param array $pathMapping
   *   The path mapping array.
   *
   * @return array
   *   The path translation map.
   */
  private function buildPathTranslationMap(array $componentTreeData, array $pathMapping): array {
    $pathMap = [];

    // Map field-level validation paths from ComponentTreeItemList->validate().
    foreach ($componentTreeData as $index => $component) {
      $uuid = $component['uuid'];
      if (isset($pathMapping[$uuid])) {
        $originalPath = $pathMapping[$uuid];

        // Map component field paths from field-level validation.
        // The actual violation paths are just numeric indices.
        $pathMap["{$index}.component_id"] = $originalPath;
        $pathMap["{$index}.uuid"] = $originalPath;
        $pathMap["{$index}.component_version"] = $originalPath;
        $pathMap["{$index}.parent_uuid"] = $originalPath;

        // For slot validation errors, point to the parent component.
        $pathMap["{$index}.slot"] = isset($component['parent_uuid'])
          ? $pathMapping[$component['parent_uuid']] ?? ''
          : $originalPath;

        // Map input validation paths from field-level validation.
        $pathMap["{$index}.inputs.{$uuid}."] = $originalPath . '.props.';
      }
    }

    return $pathMap;
  }

  /**
   * Validates a field mapping for a component prop.
   *
   * Checks if a given label exists in the field suggestions for a specific
   * component and prop combination. Throws an exception if the mapping is
   * invalid.
   *
   * @param string $sdc_id
   *   The component config entity ID (e.g., 'sdc.mercury.button').
   * @param string $prop_name
   *   The prop name to validate against.
   * @param string $label
   *   The field label to validate.
   * @param string $entity_type
   *   The entity type ID (e.g., 'node').
   * @param string $bundle
   *   The entity bundle (e.g., 'article').
   *
   * @throws \Exception
   *   If the label is not a valid field suggestion for the given component
   *   and prop.
   */
  public function validateMapping(
    string $sdc_id,
    string $prop_name,
    string $label,
    string $entity_type,
    string $bundle,
  ): void {
    // Get field suggestions for the component.
    $suggestions = $this->contentTemplateHelper->getFieldSuggestions(
      $sdc_id,
      $entity_type,
      $bundle
    );

    // Check if the prop exists in suggestions.
    if (!isset($suggestions[$prop_name])) {
      throw new \Exception("Prop '$prop_name' does not have any field suggestions for component '$sdc_id'.");
    }

    // Check if the label exists in the prop's suggestions.
    $valid_labels = array_column($suggestions[$prop_name], 'label');
    if (!in_array($label, $valid_labels, TRUE)) {
      $available_labels = implode(', ', $valid_labels);
      throw new \Exception(
        "Label '$label' is not a valid field mapping for prop '$prop_name' in component '$sdc_id'. " .
        "Available labels: $available_labels"
      );
    }
  }

  /**
   * Validates placement parameters for an operation.
   *
   * Checks if the placement parameters in an operation are valid, including
   * placement type, reference UUID requirements, and component presence.
   *
   * @param array $operation
   *   The operation to validate.
   * @param int $index
   *   The operation index for error reporting.
   *
   * @return array
   *   Array of validation errors, keyed by 'Operation {index}'.
   */
  public function validatePlacementParams(array $operation, int $index): array {
    $errors = [];
    $errorKey = 'Operation ' . $index;

    if (!isset($operation['placement']) || !in_array($operation['placement'], ['above', 'below', 'inside'], TRUE)) {
      $errors[$errorKey][] = 'The placement key is missing or invalid in the operation.';
      return $errors;
    }

    $placement = $operation['placement'];
    // If placement is 'above' or 'below', `reference_uuid` must be provided.
    if (in_array($placement, ['above', 'below'], TRUE) && empty($operation['reference_uuid'])) {
      $errors[$errorKey][] = 'The reference_uuid must be provided for above/below placement.';
    }

    // If placement is 'inside', `reference_uuid` is not needed.
    if ($placement === 'inside') {
      if (!empty($operation['reference_uuid'])) {
        $errors[$errorKey][] = 'The reference_uuid is not required for inside placement.';
      }
      // If placement is 'inside', the target must not contain child components.
      if ($this->pageBuilderHelper->hasChildComponents($operation['target'])) {
        $errors[$errorKey][] = 'The target ' . $operation['target'] . ' has "inside" placement specified, but it contains child components. Select any child component in the target and use "above" or "below" placement instead.';
      }
    }

    // Operation must contain components.
    if (empty($operation['components'])) {
      $errors[$errorKey][] = 'The operation must contain components.';
    }

    return $errors;
  }

}
