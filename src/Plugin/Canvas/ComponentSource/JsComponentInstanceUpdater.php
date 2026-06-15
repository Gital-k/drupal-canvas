<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceUpdateAttemptResult;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Updates JS component instances, accepting data loss on prop shape changes.
 *
 * Unlike JsonSchemaPropsComponentInstanceUpdater, this updater allows prop
 * shape changes and existing input data for shape-changed props is dropped.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceUpdater
 */
final class JsComponentInstanceUpdater extends JsonSchemaPropsComponentInstanceUpdater {

  /**
   * {@inheritdoc}
   *
   * All changes — including prop shape changes — are allowed for JS components.
   * Data loss is accepted.
   */
  public function canUpdate(ComponentTreeItem $component_instance): bool {
    return $this->isUpdateNeeded($component_instance);
  }

  /**
   * {@inheritdoc}
   */
  public function update(ComponentTreeItem $component_instance): ComponentInstanceUpdateAttemptResult {
    if (!$this->isUpdateNeeded($component_instance)) {
      return ComponentInstanceUpdateAttemptResult::NotNeeded;
    }

    // Pre-drop inputs that cannot be preserved as-is in the target version.
    $component = $component_instance->getComponent();
    \assert($component instanceof ComponentInterface);
    $from_version = $component_instance->getComponentVersion();
    $to_version = $component->getActiveVersion();
    [$from_props, $to_props] = self::getPropDefinitions($component, $from_version, $to_version);

    $target_source = $component->loadVersion($to_version)->getComponentSource();
    $default_explicit_input = $target_source->getDefaultExplicitInput();
    $inputs = $component_instance->getInputs() ?? [];
    $needs_input_update = FALSE;
    $shape_changed_prop_names = self::getShapeChangedPropNames($from_props, $to_props);
    foreach ($shape_changed_prop_names as $prop_name) {
      if (\array_key_exists($prop_name, $inputs)) {
        unset($inputs[$prop_name]);
        $needs_input_update = TRUE;
      }
    }

    // For required props whose shape changed, pre-seed target defaults now.
    // The parent updater intentionally skips this case when a prop remained
    // required across versions.
    foreach ($shape_changed_prop_names as $prop_name) {
      $def = $to_props[$prop_name] ?? NULL;
      if (($def['required'] ?? FALSE) !== TRUE || \array_key_exists($prop_name, $inputs)) {
        continue;
      }
      \assert(\array_key_exists($prop_name, $default_explicit_input));
      \assert(\array_key_exists('value', $default_explicit_input[$prop_name]));
      $value = $default_explicit_input[$prop_name]['value'];
      if (self::isMultiValuePropDefinition($def) && !\is_array($value)) {
        $value = [$value];
      }
      $inputs[$prop_name] = $value;
      $needs_input_update = TRUE;
    }

    // Truncate before validating: cardinality-only violations must not cause
    // inputs to be dropped entirely. parent::update() would truncate too, but
    // it runs after this validation step.
    $truncated = self::applyCardinalityTruncation($inputs, $to_props);
    if ($truncated !== $inputs) {
      $inputs = $truncated;
      $needs_input_update = TRUE;
    }

    // @todo coerce values across compatible types:
    // https://git.drupalcode.org/project/canvas/-/work_items/3591618
    $invalid_prop_names = self::getInvalidPropNames(
      $target_source->validateComponentInput(
        inputValues: $inputs,
        component_instance_uuid: $component_instance->getUuid(),
        entity: NULL,
      ),
      $component_instance->getUuid(),
    );
    if (!empty($invalid_prop_names)) {
      $inputs = \array_diff_key($inputs, \array_flip($invalid_prop_names));
      $needs_input_update = TRUE;
    }

    if ($needs_input_update) {
      $component_instance->setInput($inputs);
    }

    return parent::update($component_instance);
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

}
