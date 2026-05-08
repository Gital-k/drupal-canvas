<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceUpdateAttemptResult;
use Drupal\canvas\ComponentSource\ComponentInstanceUpdaterInterface;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\StorablePropShape;

/**
 * Updates JS component instances, accepting data loss on prop shape changes.
 *
 * Unlike GeneratedFieldExplicitInputUxComponentInstanceUpdater, this updater
 * allows prop shape changes and existing input data for shape-changed props is
 * dropped.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentInstanceUpdater
 */
final class JsComponentInstanceUpdater implements ComponentInstanceUpdaterInterface {

  /**
   * {@inheritdoc}
   */
  public function isUpdateNeeded(ComponentTreeItem $component_instance): bool {
    $component = $component_instance->getComponent();
    if ($component === NULL) {
      return FALSE;
    }
    return $component_instance->getComponentVersion() !== $component->getActiveVersion();
  }

  /**
   * {@inheritdoc}
   *
   * All changes — including prop shape changes — are allowed for JS components.
   * Data loss is accepted.
   */
  public function canUpdate(ComponentTreeItem $component_instance): bool {
    if ($component_instance->getComponent() === NULL) {
      return FALSE;
    }
    return $this->isUpdateNeeded($component_instance);
  }

  /**
   * {@inheritdoc}
   */
  public function update(ComponentTreeItem $component_instance): ComponentInstanceUpdateAttemptResult {
    if (!$this->isUpdateNeeded($component_instance)) {
      return ComponentInstanceUpdateAttemptResult::NotNeeded;
    }
    $component = $component_instance->getComponent();
    \assert($component instanceof ComponentInterface);
    $from_version = $component_instance->getComponentVersion();
    $to_version = $component->getActiveVersion();
    \assert($from_version !== $to_version);

    [$from_props, $to_props] = self::getPropDefinitions($component, $from_version, $to_version);

    $inputs = $component_instance->getInputs() ?? [];
    $needs_input_update = FALSE;

    // Remove inputs for props that don't exist in the active version. Removed?
    $unknown_inputs = \array_diff_key($inputs, $to_props);
    if (count($unknown_inputs) > 0) {
      $inputs = \array_intersect_key($inputs, $to_props);
      $needs_input_update = TRUE;
    }

    // Drop inputs for props whose shape changed. Data loss is accepted for JS
    // components. Track which props were explicitly dropped so the default
    // injection logic below can bypass the "was previously required" guard.
    $explicitly_dropped = [];
    foreach (self::getShapeChangedPropNames($from_props, $to_props) as $prop_name) {
      if (\array_key_exists($prop_name, $inputs)) {
        unset($inputs[$prop_name]);
        $explicitly_dropped[$prop_name] = TRUE;
        $needs_input_update = TRUE;
      }
    }

    // Validate the projected inputs against the target version's source to
    // detect existing inputs that are invalid for the new version.
    $target_source = $component->loadVersion($to_version)->getComponentSource();
    $component_uuid = $component_instance->getUuid();
    $violations = $target_source->validateComponentInput(
      inputValues: $inputs,
      component_instance_uuid: $component_uuid,
      entity: NULL,
    );
    $invalid_prop_names = [];
    foreach ($violations as $violation) {
      $path = $violation->getPropertyPath();
      $prefix = "inputs.$component_uuid.";
      if (str_starts_with($path, $prefix)) {
        $invalid_prop_names[substr($path, \strlen($prefix))] = TRUE;
      }
    }

    // Add default prop values for required props that are new, now required,
    // invalid in the active version, or whose input was dropped due to a shape
    // change.
    foreach ($to_props as $prop_name => $def) {
      if ($def['required'] !== TRUE ||
          (\array_key_exists($prop_name, $inputs) && !isset($invalid_prop_names[$prop_name]))) {
        continue;
      }

      if (isset($from_props[$prop_name]) && !isset($explicitly_dropped[$prop_name])) {
        if (($from_props[$prop_name]['required'] === TRUE || isset($inputs[$prop_name])) && !isset($invalid_prop_names[$prop_name])) {
          continue;
        }
      }

      $default_explicit_input = $target_source->getDefaultExplicitInput();
      \assert(\array_key_exists('value', $default_explicit_input[$prop_name]) && $default_explicit_input[$prop_name]['value'] !== NULL);
      $inputs[$prop_name] = $default_explicit_input[$prop_name]['value'];
      $needs_input_update = TRUE;
    }

    if ($needs_input_update) {
      $component_instance->setInput($inputs);
    }

    $from_slots = $component->getSlotDefinitions($from_version);
    $to_slots = $component->getSlotDefinitions($to_version);
    $removed_slot_names = \array_keys(\array_diff_key($from_slots, $to_slots));
    if (count($removed_slot_names) > 0) {
      $component_tree_list = $component_instance->getParent();
      \assert($component_tree_list instanceof ComponentTreeItemList);
      $component_tree_list->filter(
        static function (ComponentTreeItem $item) use (
          $component_uuid,
          $removed_slot_names
        ): bool {
          $slot = $item->getSlot();
          return !($slot !== NULL && $item->getParentUuid(
            ) === $component_uuid && \in_array(
              $slot,
              $removed_slot_names,
              TRUE
            ));
        }
      );
    }

    $component_instance->set('component_version', $to_version);
    return ComponentInstanceUpdateAttemptResult::Latest;
  }

  /**
   * Returns names of props whose stored field type changed between versions.
   *
   * @param array $from_props
   *   Prop field definitions for the from-version.
   * @param array $to_props
   *   Prop field definitions for the to-version.
   *
   * @return list<string>
   */
  private static function getShapeChangedPropNames(array $from_props, array $to_props): array {
    $from_storable = self::toStorablePropShapes($from_props);
    $to_storable = self::toStorablePropShapes($to_props);
    $common = \array_keys(\array_intersect_key($from_storable, $to_storable));
    return \array_values(\array_filter(
      $common,
      fn (string $prop_name): bool => !$from_storable[$prop_name]->fieldDataFitsIn($to_storable[$prop_name]),
    ));
  }

  /**
   * Converts prop field definitions to StorablePropShape objects.
   *
   * @param array $prop_field_definitions
   *   The prop field definitions array from Component::getSettings().
   *
   * @return array<string, \Drupal\canvas\PropShape\StorablePropShape>
   */
  private static function toStorablePropShapes(array $prop_field_definitions): array {
    return \array_map(static function (array $def): StorablePropShape {
      $field_type_prop = StructuredDataPropExpression::fromString($def['expression']);
      \assert($field_type_prop instanceof FieldTypeBasedPropExpressionInterface);
      $is_single_value = ($def['cardinality'] ?? StorablePropShape::DEFAULT_CARDINALITY) === 1;
      $irrelevant_shape = $is_single_value
        ? new PropShape(['type' => 'object'])
        : new PropShape(['type' => 'array', 'items' => ['type' => 'object']]);
      return new StorablePropShape(
        shape: $irrelevant_shape,
        fieldTypeProp: $field_type_prop,
        fieldWidget: 'irrelevant',
        cardinality: $def['cardinality'] ?? NULL,
        fieldStorageSettings: $def['field_storage_settings'] ?? NULL,
        fieldInstanceSettings: $def['field_instance_settings'] ?? NULL,
      );
    }, $prop_field_definitions);
  }

  /**
   * Gets prop field definitions for two versions of a Component config entity.
   *
   * @return array{array, array}
   *   From-version definitions, then to-version definitions.
   */
  private static function getPropDefinitions(ComponentInterface $component, string $from_version, string $to_version): array {
    return [
      $component->getSettings($from_version)['prop_field_definitions'],
      $component->getSettings($to_version)['prop_field_definitions'],
    ];
  }

}
