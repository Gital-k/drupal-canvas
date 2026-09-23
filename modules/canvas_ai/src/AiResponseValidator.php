<?php

namespace Drupal\canvas_ai;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Validation\BasicRecursiveValidatorFactory;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

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
   */
  public function __construct(
    protected readonly BasicRecursiveValidatorFactory $validatorFactory,
    protected readonly UuidInterface $uuidService,
  ) {
  }

  /**
   * Validates the component structure.
   *
   * A boolean prop given the string "true"/"false" is coerced to a real
   * boolean before validation; that is the only value shape worth silently
   * fixing. Callers that store or forward the props must use the return
   * value, not their input, so what was validated is what gets placed.
   *
   * @param array $componentGroups
   *   The component groups to validate.
   *
   * @return array
   *   The component groups, with recognized boolean strings coerced.
   *
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   *   When validation fails.
   */
  public function validateComponentStructure(array $componentGroups): array {
    // Create a mapping of components to their original paths.
    $pathMapping = [];
    // Some violations are found outside of ComponentTreeItemList->validate():
    // props that do not exist on their component, boolean props given a
    // value that cannot be confidently interpreted, and values the component
    // source dropped rather than stored. Collect them during conversion
    // instead.
    $outOfBandViolations = new ConstraintViolationList();

    // Convert YAML structure to Canvas ComponentTreeItem format.
    $componentTreeData = $this->convertToComponentTreeData($componentGroups, NULL, NULL, 'components', $pathMapping, $outOfBandViolations);

    $componentTreeItemList = $this->createDanglingComponentTreeItemList();
    $componentTreeItemList->setValue($componentTreeData);
    $violations = $componentTreeItemList->validate();

    if ($violations->count() > 0 || $outOfBandViolations->count() > 0) {
      $translatedViolations = $this->translateConstraintPropertyPathsAndRoot(
        $this->buildPathTranslationMap($componentTreeData, $pathMapping),
        $violations,
        ''
      );
      // Out-of-band violations are built with already-translated paths.
      $translatedViolations->addAll($outOfBandViolations);
      throw new ConstraintViolationException(
        $translatedViolations,
        'Component validation errors'
      );
    }
    return $componentGroups;
  }

  /**
   * Converts component groups to component tree data.
   *
   * @param array $componentGroups
   *   The component groups to convert. Prop values are normalized in place.
   * @param string|null $parentUuid
   *   The parent UUID, if any.
   * @param string|null $slotName
   *   The slot name, if any.
   * @param string $pathPrefix
   *   The path prefix for the current level.
   * @param array &$pathMapping
   *   Reference to path mapping array.
   * @param \Symfony\Component\Validator\ConstraintViolationList $outOfBandViolations
   *   Collects violations found outside of field-level validation.
   *
   * @return array
   *   The converted component tree data.
   */
  private function convertToComponentTreeData(
    array &$componentGroups,
    ?string $parentUuid,
    ?string $slotName,
    string $pathPrefix,
    array &$pathMapping,
    ConstraintViolationList $outOfBandViolations,
  ): array {
    $componentTreeData = [];
    foreach ($componentGroups as $groupIndex => &$componentGroup) {
      if (!\is_array($componentGroup)) {
        continue;
      }
      foreach ($componentGroup as $componentId => &$componentData) {
        $componentUuid = $this->uuidService->generate();

        $componentPath = \sprintf('%s.%d.[%s]', $pathPrefix, $groupIndex, $componentId);
        $pathMapping[$componentUuid] = $componentPath;

        // Create a temp version if the component does not exist to allow
        // validation to proceed. The constraints will flag invalid components
        // later.
        $component = Component::load($componentId);
        $componentVersion = $component ? $component->getActiveVersion() : "temp-version-$componentUuid";
        $inputs = [];
        if ($component instanceof Component && !empty($componentData['props'])) {
          $clientNormalized = $component->normalizeForClientSide()->values;
          $propSources = $clientNormalized['propSources'] ?? NULL;
          $this->collectUnknownPropViolations(
            $componentId,
            $componentData['props'],
            $propSources,
            $componentPath,
            $outOfBandViolations
          );
          if (\is_array($componentData['props'])) {
            $componentData['props'] = $this->normalizeBooleanProps(
              $componentData['props'],
              $propSources, $componentId,
              $componentPath,
              $outOfBandViolations
            );
            $clientModel['source'] = $propSources ?? [];
            $clientModel['resolved'] = $componentData['props'];
            $inputs = $component->getComponentSource()->clientModelToInput($componentUuid, $component, $clientModel, NULL);
            $this->collectUnstorableValueViolations(
              $componentId,
              $componentData['props'],
              $propSources,
              $inputs,
              $componentPath,
              $outOfBandViolations
            );
          }
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
        if (isset($componentData['slots']) && \is_array($componentData['slots'])) {
          foreach ($componentData['slots'] as $slot => &$slotComponentGroups) {
            if (!\is_array($slotComponentGroups)) {
              continue;
            }
            $slotPath = \sprintf('%s.slots.%s', $componentPath, $slot);
            $componentTreeData = array_merge(
              $componentTreeData,
              $this->convertToComponentTreeData(
                $slotComponentGroups,
                $componentUuid,
                $slot,
                $slotPath,
                $pathMapping,
                $outOfBandViolations
              )
            );
          }
          unset($slotComponentGroups);
        }
      }
      unset($componentData);
    }
    unset($componentGroup);
    return $componentTreeData;
  }

  /**
   * Collects violations for AI-supplied props a component does not define.
   *
   * @param string $componentId
   *   The component ID.
   * @param mixed $props
   *   The AI-supplied props value.
   * @param array|null $propSources
   *   The component's defined prop sources, or NULL for sources that are not
   *   prop-based (e.g. block components).
   * @param string $componentPath
   *   The component path, used to build the violation property path.
   * @param \Symfony\Component\Validator\ConstraintViolationList $violations
   *   The list to add violations to.
   */
  private function collectUnknownPropViolations(string $componentId, mixed $props, ?array $propSources, string $componentPath, ConstraintViolationList $violations): void {
    // @todo Remove once \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::clientModelToInput() records dropped props in its own violation list.
    if (!\is_array($props)) {
      $this->addGarbageInputViolation(
        $violations,
        \sprintf('Component `%s`: the props must be a mapping of prop names to values.', $componentId),
        \sprintf('%s.props', $componentPath),
        $props,
      );
      return;
    }
    // Unknown props on block components are NOT validated here. Block inputs are
    // plugin settings, not JSON-Schema props, so blocks expose no propSources to
    // diff against. This matches Canvas core, which does not flag them either:
    // BlockComponent::clientModelToInput() silently drops unknown keys and
    // BlockComponent::validateComponentInput() never checks for them.
    // @todo Validate blocks too once BlockComponent::clientModelToInput() reports its dropped unknown keys via the $violations list it already receives.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::clientModelToInput()
    if ($propSources === NULL) {
      return;
    }
    // clientModelToInput() only reads resolved values for props the component
    // defines, so props that do not exist are silently dropped and would
    // otherwise pass validation.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::clientModelToInput()
    foreach (\array_diff_key($props, $propSources) as $propName => $propValue) {
      $this->addGarbageInputViolation(
        $violations,
        \sprintf('Component `%s`: the `%s` prop is not defined.', $componentId, $propName),
        \sprintf('%s.props.%s', $componentPath, $propName),
        $propValue,
      );
    }
  }

  /**
   * Coerces "true"/"false" strings on boolean props to real booleans.
   *
   * A client may write the strings "true"/"false" instead of a YAML/JSON
   * boolean literal; that is the only value shape unambiguous enough to fix
   * silently. Anything else on a boolean prop is reported instead of being
   * passed through: `Evaluator::doEvaluate()` casts any non-empty string to
   * `TRUE`, which would silently store the wrong value with no error at all.
   *
   * @param array $props
   *   The AI-supplied props.
   * @param array|null $propSources
   *   The component's defined prop sources, or NULL for sources that are not
   *   prop-based (e.g. block components).
   * @param string $componentId
   *   The component ID, used in violation messages.
   * @param string $componentPath
   *   The component path, used to build the violation property path.
   * @param \Symfony\Component\Validator\ConstraintViolationList $violations
   *   The list to add a violation to when a value cannot be interpreted.
   *
   * @return array
   *   The props, with recognized boolean strings coerced to real booleans.
   *
   * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::doEvaluate()
   */
  private function normalizeBooleanProps(array $props, ?array $propSources, string $componentId, string $componentPath, ConstraintViolationList $violations): array {
    if ($propSources === NULL) {
      return $props;
    }
    foreach ($props as $propName => $propValue) {
      $isBooleanProp = ($propSources[$propName]['jsonSchema']['type'] ?? NULL) === 'boolean';
      if (!$isBooleanProp || \is_bool($propValue) || $propValue === NULL) {
        continue;
      }
      if (\is_string($propValue)) {
        $word = \strtolower($propValue);
        if ($word === 'true' || $word === 'false') {
          $props[$propName] = $word === 'true';
          continue;
        }
      }
      $this->addGarbageInputViolation(
        $violations,
        \sprintf('Component `%s`: the `%s` prop value %s cannot be stored: expected a boolean (`true` or `false`).', $componentId, $propName, \json_encode($propValue)),
        \sprintf('%s.props.%s', $componentPath, $propName),
        $propValue,
      );
    }
    return $props;
  }

  /**
   * Collects violations for supplied values the component source dropped.
   *
   * `ComponentSourceInterface::clientModelToInput()` leaves out a prop whose
   * value it cannot store in the prop's field type: an image object or URL
   * string handed to a media-bound prop, or a value the field considers
   * empty. Field-level validation then never sees that prop, so the
   * placement would validate and the component would render without it.
   * Report the dropped value instead, so the model can supply one of the
   * right shape. This naturally only fires for reference/media-shaped props:
   * a bad enum or boolean value fails a different way and is not dropped.
   *
   * @param string $componentId
   *   The component ID.
   * @param array $props
   *   The AI-supplied props.
   * @param array|null $propSources
   *   The component's defined prop sources, or NULL for sources that are not
   *   prop-based (e.g. block components).
   * @param array $inputs
   *   The inputs `clientModelToInput()` produced from those props.
   * @param string $componentPath
   *   The component path, used to build the violation property path.
   * @param \Symfony\Component\Validator\ConstraintViolationList $violations
   *   The list to add violations to.
   */
  private function collectUnstorableValueViolations(string $componentId, array $props, ?array $propSources, array $inputs, string $componentPath, ConstraintViolationList $violations): void {
    if ($propSources === NULL) {
      return;
    }
    foreach ($props as $propName => $propValue) {
      if (!isset($propSources[$propName]) || \array_key_exists($propName, $inputs)) {
        continue;
      }
      $storage = $propSources[$propName]['sourceTypeSettings']['storage']['target_type'] ?? NULL;
      $hint = \is_string($storage)
        ? \sprintf('This prop stores a reference to a %s entity: give the ID of an existing one, or copy the default image object exactly as the component details give it.', $storage)
        : 'Give a non-empty value of the type the component details describe, or omit the prop.';
      $this->addGarbageInputViolation(
        $violations,
        \sprintf('Component `%s`: the value given for the `%s` prop cannot be stored. %s', $componentId, $propName, $hint),
        \sprintf('%s.props.%s', $componentPath, $propName),
        $propValue,
      );
    }
  }

  /**
   * Adds a violation for a prop value that cannot be stored as given.
   *
   * @param \Symfony\Component\Validator\ConstraintViolationList $violations
   *   The list to add the violation to.
   * @param string $message
   *   The violation message.
   * @param string $path
   *   The violation's property path.
   * @param mixed $value
   *   The offending value, for the violation's `getInvalidValue()`.
   */
  private function addGarbageInputViolation(ConstraintViolationList $violations, string $message, string $path, mixed $value): void {
    $violations->add(new ConstraintViolation(
      $message,
      NULL,
      [],
      NULL,
      $path,
      $value,
      code: ComponentTreeItem::VIOLATION_CODE_GARBAGE_INPUT,
    ));
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

}
