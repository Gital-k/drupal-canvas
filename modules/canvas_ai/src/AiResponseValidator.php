<?php

namespace Drupal\canvas_ai;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Component\Serialization\Json;
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
   * @param \Drupal\canvas_ai\CanvasAiTempStore $canvasAiTempStore
   *   The Canvas AI tempstore service.
   */
  public function __construct(
    protected readonly BasicRecursiveValidatorFactory $validatorFactory,
    protected readonly UuidInterface $uuidService,
    protected readonly CanvasAiTempStore $canvasAiTempStore,
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

        $componentPath = \sprintf('%s.%d.[%s]', $pathPrefix, $groupIndex, $componentId);
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
            $slotPath = \sprintf('%s.slots.%s', $componentPath, $slot);
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
   * Validates prop values for a component update.
   *
   * @param string $componentId
   *   The component ID (e.g. "sdc.byte_theme.card").
   * @param array $props
   *   The prop values provided by the update_component_data tool.
   *
   * @throws \InvalidArgumentException
   *   When the component does not exist or media props are provided as arrays.
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   *   When validation fails.
   */
  public function validateComponentPropUpdate(string $componentId, array $props): void {
    // Load the component.
    $component = Component::load($componentId);
    if (!$component instanceof Component) {
      throw new \InvalidArgumentException(
        sprintf('Component "%s" does not exist.', $componentId)
      );
    }

    $source = $component->getComponentSource();
    $clientNormalized = $component->normalizeForClientSide()->values;
    $propSources = $clientNormalized['propSources'] ?? [];

    // Validate media props: if the tool provided an array of values (e.g.
    // src, alt, width, height) for an entity_reference prop, reject it and
    // instruct to provide the media ID instead.
    foreach ($propSources as $propName => $propDefinition) {
      if (!array_key_exists($propName, $props)) {
        continue;
      }
      $sourceType = $propDefinition['sourceType'] ?? '';
      if (str_contains($sourceType, 'entity_reference') && is_array($props[$propName])) {
        throw new \InvalidArgumentException(
          sprintf(
            'The prop "%s" is a media reference. To update it, provide the media ID instead of an array of values like src, alt, width, height.',
            $propName
          )
        );
      }
    }

    // Fill in default values for required props not provided by the tool.
    foreach ($propSources as $propName => $propDefinition) {
      if (array_key_exists($propName, $props)) {
        continue;
      }
      if (!empty($propDefinition['required'])) {
        $defaultValue = $propDefinition['default_values']['resolved'] ?? NULL;
        if ($defaultValue !== NULL) {
          $props[$propName] = $defaultValue;
        }
      }
    }

    // Build a single-component tree and validate.
    $componentUuid = $this->uuidService->generate();
    $componentVersion = $component->getActiveVersion();

    $clientModel = [
      'source' => $propSources,
      'resolved' => $props,
    ];
    $inputs = $source->clientModelToInput($componentUuid, $component, $clientModel, NULL);

    $componentTreeData = [
      [
        'uuid' => $componentUuid,
        'component_id' => $componentId,
        'component_version' => $componentVersion,
        'inputs' => $inputs,
      ],
    ];

    $componentTreeItemList = $this->createDanglingComponentTreeItemList();
    $componentTreeItemList->setValue($componentTreeData);
    $violations = $componentTreeItemList->validate();

    if ($violations->count() > 0) {
      throw new ConstraintViolationException(
        $violations,
        'Component prop validation errors'
      );
    }
  }

  /**
   * Validates that a component exists in the current page.
   *
   * @param string $uuid
   *   The UUID of the component to validate.
   *
   * @throws \InvalidArgumentException
   *   When the component does not exist in the page.
   */
  public function validateComponentExistsInPage(string $uuid): void {
    $componentsJson = $this->canvasAiTempStore->getData(
      CanvasAiTempStore::COMPONENTS_IN_PAGE_WITH_PROP_VALUES_KEY
    );

    // Decode to array.
    $components = !empty($componentsJson) ? Json::decode($componentsJson) : [];

    if (!is_array($components) || !array_key_exists($uuid, $components)) {
      throw new \InvalidArgumentException(
        sprintf('Component with UUID "%s" does not exist in the current page.', $uuid)
      );
    }
  }

}
