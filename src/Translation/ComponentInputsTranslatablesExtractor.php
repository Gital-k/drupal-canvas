<?php

declare(strict_types=1);

namespace Drupal\canvas\Translation;

use Drupal\canvas\Config\Schema\ComponentInputsMapping;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\Config\Schema\Sequence;
use Drupal\Core\Config\Schema\TypedConfigInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Discovers the translatable strings in a single component instance's inputs.
 *
 * The per-instance discovery + write-back engine: it converts a component
 * instance's inputs to typed config, reads each prop's translatability metadata
 * from config schema, and returns a flat list of translatable strings keyed by
 * a dotted prop path. It is the single source of truth for "what is
 * translatable in a component instance" — shared by every translation feature:
 * the translation-system-agnostic ComponentTreeTranslator facade, and the TMGMT
 * adapters under src/Tmgmt/. It is itself free of any translation system: it
 * emits and consumes plain values, never TMGMT's data structure.
 *
 * @see \Drupal\canvas\Translation\ComponentTreeTranslator
 * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
 *
 * @internal
 */
final class ComponentInputsTranslatablesExtractor {

  public function __construct(
    private readonly TypedConfigManagerInterface $typedConfigManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Extracts the translatable strings from one component instance's inputs.
   *
   * @param \Drupal\canvas\Config\Schema\ComponentInputsMapping $schema
   *   The typed-config representation of the instance's `inputs`.
   * @param array $inputs
   *   The instance's `inputs` values.
   *
   * @return array<string, array{propPath: list<string|int>, label: string, sourceValue: string, format: string|null}>
   *   Translatable strings keyed by dotted prop path (e.g. 'text', 'href.uri',
   *   'deeply_nested_translatable.0.bar'). `sourceValue` is the empty string
   *   for a translatable-but-unpopulated optional prop.
   */
  public function extractInstance(ComponentInputsMapping $schema, array $inputs): array {
    // Per `type: canvas.component_tree_node`, some keys must definitely exist,
    // assert the ones that this class needs.
    $parent = $schema->getParent();
    $component_instance = $parent?->getValue();
    \assert(\is_array($component_instance)
      && \array_key_exists('component_id', $component_instance)
      && \array_key_exists('component_version', $component_instance)
      && \array_key_exists('inputs', $component_instance));
    $component_id = $component_instance['component_id'];
    $component_version = $component_instance['component_version'];

    $component = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID)->load($component_id);
    if (!$component instanceof ComponentInterface) {
      // Non-existent Component: walk the schema as-is.
      return $this->walk($schema, $inputs, [], include_empty: FALSE);
    }
    $component_source = $component->loadVersion($component_version)->getComponentSource();
    if (!$component_source instanceof JsonSchemaPropsComponentSourceBase) {
      return $this->walk($schema, $inputs, [], include_empty: FALSE);
    }

    // TRICKY: note that $schema is a ComponentInputsMapping, which this then
    // essentially ignores. This generates a different `type: mapping`
    // definition: one that corresponds to the concrete `field.value.*`s used
    // by the props populated by StaticPropSources in the default translation
    // (and `type: ignore`s for any props populated by any other kind of prop
    // source).
    // The prop set comes from the *versioned* prop field definitions — like
    // the generated config schema mapping, never from the live
    // implementation's JSON Schema, which cannot describe the component
    // version this instance references. Any populated input without a field
    // definition still gets a (non-translatable) `type: ignore` entry.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::getConfigSchemaMapping()
    $concrete_schema = [
      'type' => 'mapping',
    ];
    $prop_field_definitions = $component_source->getConfiguration()['prop_field_definitions'] ?? [];
    foreach (\array_keys($prop_field_definitions + $inputs) as $prop_name) {
      $value = $inputs[$prop_name] ?? NULL;
      if (!JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::isStaticPropSource($value)) {
        // No need to translate non-static prop sources.
        $concrete_schema['mapping'][$prop_name] = [
          'type' => 'ignore',
          'label' => $component_source->getMetadata()->schema['properties'][$prop_name]['title'] ?? $prop_name,
        ];
        continue;
      }
      $concrete_schema['mapping'][$prop_name] = $this->buildConcreteSchemaFromPropSource($prop_name, $component_source);
    }

    // Ensure optional component inputs not populated in the default translation
    // are still present with a NULL value in $inputs. This is necessary because
    // TypedConfig's ArrayElement::getAllKeys() only returns keys present in the
    // data, silently skipping schema-defined keys with no corresponding data.
    // @see \Drupal\canvas\ConfigTranslation\CanvasComponentTreeItemInputsMappingFormElement::ensureOmittedOptionalInputsAreTranslatable()
    // @see \Drupal\Core\Config\Schema\ArrayElement::getAllKeys()
    foreach ($concrete_schema['mapping'] ?? [] as $prop_name => $prop_schema) {
      if (\array_key_exists($prop_name, $inputs)) {
        continue;
      }
      // Pretend this config property path is populated by NULL, so the optional
      // prop is still surfaced as translatable.
      $inputs[$prop_name] = NULL;
    }

    $name = $schema->getName();
    if (\is_int($name)) {
      $name = (string) $name;
    }
    $concrete_element = $this->typedConfigManager->create($this->typedConfigManager->buildDataDefinition($concrete_schema, $inputs, $name, $parent), $inputs, $name, $parent);
    \assert($concrete_element instanceof TypedConfigInterface);
    return $this->walk($concrete_element, $inputs, [], include_empty: TRUE);
  }

  /**
   * Writes translations back into one component instance's inputs.
   *
   * @param array $inputs
   *   The instance's current `inputs` values.
   * @param array<string, string> $translations
   *   Translated strings keyed by the dotted prop path produced by
   *   ::extractInstance().
   * @param \Drupal\canvas\Config\Schema\ComponentInputsMapping $schema
   *   The typed-config representation of the instance's `inputs`, used to order
   *   and prune the result to the props the component currently defines.
   *
   * @return array
   *   The updated `inputs`.
   */
  public static function applyToInstance(array $inputs, array $translations, ComponentInputsMapping $schema): array {
    foreach ($translations as $dotted_path => $text) {
      // Numeric path segments (sequence indices) are cast back to integers by
      // PHP when used as array keys, so an exploded dotted path addresses the
      // same slots as the originally stored value.
      NestedArray::setValue($inputs, \explode('.', (string) $dotted_path), $text);
    }

    // Respect `inputs` order dictated by the ComponentSource plugin's
    // `inputs_config_schema_generator` handler, and keep only props the
    // component still defines.
    // @see \Drupal\canvas\Attribute\ComponentSource::__construct(inputs_config_schema_generator)
    // @see \Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface
    $config_schema_order = $schema->getValidKeys();
    $inputs_in_schema_order = \array_replace(
      // Schema-ordered, all NULL.
      \array_fill_keys($config_schema_order, NULL),
      // $inputs filtered to schema keys only.
      \array_intersect_key($inputs, \array_flip($config_schema_order)),
    );
    // Write only non-NULL (preserve FALSE, 0, '', []).
    return \array_filter($inputs_in_schema_order, static fn($v) => $v !== NULL);
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
   * @return \Drupal\canvas\Config\Schema\ComponentInputsMapping
   *   The Typed Config representation of the component instance's `inputs`.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\ConfigComponentTreeTrait::conjureFieldItemObject()
   */
  public function mappingForItem(ComponentTreeItem $item): ComponentInputsMapping {
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
    $field_value = $this->typedConfigManager->create(
      $this->typedConfigManager->buildDataDefinition($parent_definition, $values, $name),
      $values,
      $name,
    );
    $definition = [
      'type' => 'mapping',
      'class' => ComponentInputsMapping::class,
      'label' => 'Input values for each component instance in the component tree',
    ];
    $inputs = $item->getInputs();
    /** @var \Drupal\canvas\Config\Schema\ComponentInputsMapping */
    return $this->typedConfigManager->create(
      $this->typedConfigManager->buildDataDefinition($definition, $inputs, 'inputs', $field_value),
      $inputs,
      'inputs',
      $field_value,
    );
  }

  /**
   * Build a concrete config schema from a static prop source.
   *
   * @param string $prop_name
   *   Prop name.
   * @param \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase $component_source
   *   Component source.
   *
   * @return array
   *   Concrete schema definition for the prop.
   */
  private function buildConcreteSchemaFromPropSource(string $prop_name, JsonSchemaPropsComponentSourceBase $component_source): array {
    $concrete_schema = [
      'type' => 'ignore',
      'label' => $component_source->getMetadata()->schema['properties'][$prop_name]['title'] ?? $prop_name,
    ];
    try {
      $static_prop_source = $component_source->getDefaultStaticPropSource($prop_name, FALSE);
    }
    catch (\OutOfRangeException) {
      return $concrete_schema;
    }
    $field_type = $static_prop_source->fieldItemList->getFieldDefinition()->getType();
    $cardinality = $static_prop_source->getCardinality();
    $field_value_schema = \sprintf('field.value.%s', $field_type);
    if (!$this->typedConfigManager->hasDefinition($field_value_schema)) {
      return $concrete_schema;
    }

    // Use the raw definition whenever possible, this maximally preserves the
    // originally specified config schema type, such as `type: text_format`.
    // That is necessary to detect the text format of a rich-text prop.
    $prop_field_definition_raw = $this->typedConfigManager->getDefinitions()[$field_value_schema];

    // The number of non-computed stored properties on the field item — not the
    // count of `field.value.*` mapping keys — determines the runtime shape of
    // the stored value. StaticPropSource::denormalizeValue() collapses a
    // single-property field item to a scalar and keeps a multi-property one as
    // a mapping, so the generated schema must follow the stored-property count.
    // @see \Drupal\canvas\PropSource\StaticPropSource::denormalizeValue()
    $item_definition = $static_prop_source->fieldItemList->getItemDefinition();
    \assert($item_definition instanceof FieldItemDataDefinitionInterface);
    $stored_prop_count = \count(\array_filter(
      $item_definition->getPropertyDefinitions(),
      fn(DataDefinitionInterface $def) => !$def->isComputed(),
    ));

    if ($stored_prop_count === 1) {
      // A single stored property collapses to a scalar value. Decide whether it
      // is translatable with the SAME rule the config schema generator uses —
      // derived from this component *version's* stored prop field definitions,
      // not the live implementation — so TMGMT never offers an input the
      // generated config schema would reject on save (and vice versa). A
      // single-stored-property field reachable by a translatable shape is
      // always a plain string — rich text (value+format) and URI-esque strings
      // (link: uri+title+options) are multi-property field types — hence the
      // collapsed scalar is `type: label`.
      // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::isExplicitInputTranslatable()
      // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::collapse()
      if (!$component_source->isExplicitInputTranslatable($prop_name)) {
        // Leave $concrete_schema as `type: ignore`: not translatable.
        return $concrete_schema;
      }
      // `type: label` is translatable; cardinality decides scalar vs sequence.
      if ($cardinality === 1) {
        $concrete_schema['type'] = 'label';
        return $concrete_schema;
      }
      $concrete_schema['type'] = 'sequence';
      $concrete_schema['sequence']['type'] = 'label';
      return $concrete_schema;
    }

    // Multiple stored properties keep the raw `field.value.*` definition, so
    // per-property `translatable` flags (e.g. a link's `uri`, a text field's
    // `value` vs `format`) and richer types (e.g. `text_format`) are preserved.
    if ($cardinality === 1) {
      return $prop_field_definition_raw;
    }
    // Multi-cardinality AND multiple stored properties.
    $concrete_schema['type'] = 'sequence';
    $concrete_schema['sequence'] = $prop_field_definition_raw;
    return $concrete_schema;
  }

  /**
   * Recursively collects translatable leaves, keyed by dotted prop path.
   *
   * Surfaces translatable optional props that are unpopulated in the source (so
   * a translation may provide a value even when the source leaves it empty):
   * such a leaf is returned with an empty `sourceValue`. Empty values nested
   * below a top-level prop are skipped, matching the source data's shape.
   *
   * @param \Drupal\Core\Config\Schema\TypedConfigInterface|\Drupal\Core\Config\Schema\Mapping|\Drupal\Core\Config\Schema\Sequence $schema
   *   The schema element to walk.
   * @param array $data
   *   The values at this level.
   * @param list<string|int> $path
   *   The prop path accumulated so far.
   * @param bool $include_empty
   *   Whether to surface translatable-but-empty props at this level.
   *
   * @return array<string, array{propPath: list<string|int>, label: string, sourceValue: string, format: string|null}>
   *   Translatable leaves keyed by dotted prop path.
   */
  private function walk(TypedConfigInterface|Mapping|Sequence $schema, array $data, array $path, bool $include_empty): array {
    $leaves = [];
    foreach ($schema as $key => $element) {
      $child_path = [...$path, $key];
      $definition = $element->getDataDefinition();
      if ($element instanceof Mapping || $element instanceof Sequence) {
        // Nested values follow the source shape: do not surface empties below a
        // top-level prop.
        $leaves += $this->walk($element, $data[$key] ?? [], $child_path, include_empty: FALSE);
        continue;
      }
      // @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible
      if (!isset($definition['translatable']) || !isset($definition['type']) && !$include_empty && empty($data[$key])) {
        continue;
      }
      $value = $data[$key] ?? NULL;
      // A translatable value is always a single string. Anything else (e.g. an
      // array left by a `type: ignore` prop) is not a translatable string.
      if (\is_array($value)) {
        continue;
      }
      if (empty($value)) {
        if (!self::isDirectInputProp($schema)) {
          continue;
        }
        // A translatable optional prop, unpopulated in the source: surface it
        // with an empty source value so a translation can still be provided.
        $value = '';
      }
      $leaf = [
        'propPath' => $child_path,
        'label' => (string) $definition['label'],
        'sourceValue' => (string) $value,
        'format' => NULL,
      ];
      // A `type: text_format` value carries its text format alongside it;
      // expose it so consumers can render the appropriate (rich-text) editor.
      if ($schema->getDataDefinition()->getDataType() === 'text_format' && \array_key_exists('format', $data)) {
        $leaf['format'] = $data['format'];
      }
      $leaves[\implode('.', $child_path)] = $leaf;
    }
    return $leaves;
  }

  /**
   * Whether the given schema is a component instance's inputs mapping.
   *
   * Only direct props of a component instance's inputs surface when empty;
   * values nested deeper follow the source data's shape. This holds both for
   * the concrete `type: mapping` generated from a
   * JsonSchemaPropsComponentSource AND for the raw ComponentInputsMapping
   * handled by the fallback paths in
   * ::extractTranslatables() (a Component that fails to load, or whose source
   * is not a JsonSchemaPropsComponentSourceBase, e.g. a block). In both cases
   * an empty but translatable input must still be offered for translation via
   * the `∅` placeholder: a value can legitimately be empty in the source
   * language yet translated to a non-empty value in the target language.
   *
   * @see ::extractTranslatables()
   */
  private static function isDirectInputProp(TypedConfigInterface $schema): bool {
    return $schema->getParent()?->getDataDefinition()->getDataType() === 'canvas.component_tree_node';
  }

}
