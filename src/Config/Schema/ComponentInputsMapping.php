<?php

declare(strict_types=1);

namespace Drupal\canvas\Config\Schema;

use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Generates a mapping definition for a component instance's inputs.
 *
 * Delegates to ComponentInputs::resolveConfigSchemaMapping() for the
 * actual resolution logic: this ensures a single source of truth for per-key
 * config schema metadata for both config translation (this class) and content
 * translation (ComponentInputs::getTranslatableInputKeys()).
 *
 * @internal
 *
 * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::resolveConfigSchemaMapping()
 */
final class ComponentInputsMapping extends Mapping {

  public function __construct(DataDefinitionInterface $definition, $name = NULL, ?TypedDataInterface $parent = NULL) {
    \assert($definition instanceof MapDataDefinition);

    if ($parent === NULL) {
      throw new \LogicException('$parent cannot be NULL; this can only be used for the `inputs` key in `type: canvas.component_tree_node`.');
    }
    // `field.value.component_tree` is a subtype of the
    // `canvas.component_tree_node` config schema type.
    if (!\in_array($parent->getDataDefinition()->getDataType(), [
      'canvas.component_tree_node',
      'field.value.component_tree',
    ], TRUE)) {
      throw new \LogicException(\sprintf('$parent must be of type `canvas.component_tree_node`, `%s` given.', $parent->getDataDefinition()->getDataType()));
    }

    // Per `type: canvas.component_tree_node`, some keys must definitely exist,
    // assert the ones that this class needs.
    $component_instance = $parent->getValue();
    \assert(\array_key_exists('component_id', $component_instance));
    \assert(\array_key_exists('component_version', $component_instance));
    \assert(\array_key_exists('inputs', $component_instance));
    $component_id = $component_instance['component_id'];
    $component_version = $component_instance['component_version'];
    $actual_inputs = $component_instance['inputs'];

    // Delegate to ComponentInputs for the actual resolution logic.
    // @see \Drupal\canvas\Plugin\DataType\ComponentInputs::resolveConfigSchemaMapping()
    $definition['mapping'] = ComponentInputs::resolveConfigSchemaMapping($component_id, $component_version, $actual_inputs ?? []);

    parent::__construct($definition, $name, $parent);
  }

  /**
   * Gets a ComponentTreeItem's `inputs` property as equivalent Typed Config.
   *
   * Note: This is the inverse of what happens during validation: then Typed
   * Config is converted to ComponentTreeItem (content entity field) object
   * representations. It happens to be that configuration schema has more
   * translatability metadata, so for translation purposes, it's the other way
   * around.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem $item
   *   A component instance.
   *
   * @return self
   *   The Typed Config representation of the component instance's `inputs`.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\ConfigComponentTreeTrait::conjureFieldItemObject()
   */
  public static function forItem(ComponentTreeItem $item): self {
    // TRICKY: this cannot use constructor injection because Typed Config
    // objects are instantiated by the Typed Config manager.
    $typed_config_manager = \Drupal::service('config.typed');
    \assert($typed_config_manager instanceof TypedConfigManagerInterface);

    $values = $item->getValue();
    // Content fields store `inputs` as a JSON string; config-defined component
    // trees provide it already decoded. Tolerate both.
    if (\is_string($values['inputs'])) {
      $values['inputs'] = \json_decode($values['inputs'], TRUE, flags: \JSON_THROW_ON_ERROR);
    }
    $parent_definition = [
      'type' => 'canvas.component_tree_node',
    ];
    $name = (string) $item->getName();
    $field_value = $typed_config_manager->create(
      $typed_config_manager->buildDataDefinition($parent_definition, $values, $name),
      $values,
      $name,
    );
    $definition = [
      'type' => 'mapping',
      'class' => self::class,
      'label' => 'Input values for each component instance in the component tree',
    ];
    $inputs = $item->getInputs();
    /** @var self */
    return $typed_config_manager->create(
      $typed_config_manager->buildDataDefinition($definition, $inputs, 'inputs', $field_value),
      $inputs,
      'inputs',
      $field_value,
    );
  }

}
