<?php

declare(strict_types=1);

namespace Drupal\canvas\Translation;

use Drupal\canvas\Config\Schema\ComponentInputsMapping;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\Element;

/**
 * Extracts and writes back translatable strings for component_tree fields.
 *
 * The shared, translation-system-agnostic home of the component-tree
 * extract/write-back logic: any translation feature can depend on this service
 * directly. The heavy lifting — converting a component instance's inputs to
 * typed config and reading its translatability metadata from config schema —
 * lives in ComponentInputsTranslatablesExtractor.
 *
 * @see \Drupal\canvas\Translation\ComponentInputsTranslatablesExtractor
 * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
 */
final class ComponentTreeTranslator {

  public function __construct(
    private readonly TypedConfigManagerInterface $typedConfigManager,
    private readonly ComponentInputsTranslatablesExtractor $componentInputsTranslatablesExtractor,
  ) {}

  /**
   * Extracts translatable data for a component_tree field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   A `component_tree` field.
   *
   * @return array
   *   A structure keyed by field item delta; each delta holds a nested tree of
   *   translatable leaves: ['#label' => …, '#text' => …, '#translate' => TRUE,
   *   '#format' => …]. The top level also carries a '#label' for the field.
   */
  public function extractTranslatableData(FieldItemListInterface $field): array {
    $items = [];

    foreach ($field as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);
      $mapping = $this->asComponentInputsMapping($item);
      $items[$delta] = $this->componentInputsTranslatablesExtractor->extractTranslatables($mapping, $item->getInputs() ?? []);
    }

    return ['#label' => $field->getFieldDefinition()->getLabel()] + $items;
  }

  /**
   * Writes translations from a translation-data tree back into a field.
   *
   * Each translatable leaf in $field_data must carry a #translation['#text']
   * value. Only the leaves present in $field_data are touched; every other
   * input of every component instance is preserved.
   *
   * @param array $field_data
   *   Translation data, keyed by field item delta; each delta holds a nested
   *   tree of translatable leaves carrying #translation['#text'].
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The target translation's `component_tree` field.
   */
  public function setTranslations(array $field_data, FieldItemListInterface $field): void {
    foreach (Element::children($field_data) as $delta) {
      $item_data = $field_data[$delta];
      if (!$field->offsetExists($delta)) {
        continue;
      }

      $item = $field->offsetGet($delta);
      \assert($item instanceof ComponentTreeItem);

      $inputs = $item->getInputs() ?? [];

      $changed = FALSE;
      foreach (Element::children($item_data) as $prop_key) {
        $found = FALSE;
        $inputs[$prop_key] = self::writeNestedTranslationsToInputs($item_data[$prop_key], $inputs[$prop_key] ?? NULL, $found);
        if ($found) {
          $changed = TRUE;
        }
      }

      if ($changed) {
        // Respect `inputs` order dictated by the ComponentSource plugin's
        // `inputs_config_schema_generator` handler.
        // @see \Drupal\canvas\Attribute\ComponentSource::__construct(inputs_config_schema_generator)
        // @see \Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface
        $config_schema_order = $this->asComponentInputsMapping($item)->getValidKeys();
        $inputs_in_schema_order = \array_replace(
          // Schema-ordered, all NULL.
          \array_fill_keys($config_schema_order, NULL),
          // $inputs filtered to schema keys only.
          \array_intersect_key($inputs, \array_flip($config_schema_order)),
        );
        // Write only non-NULL (preserve FALSE, 0, '', []).
        $item->setInput(\array_filter($inputs_in_schema_order, static fn($v) => $v !== NULL));
      }
    }
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
  private function asComponentInputsMapping(ComponentTreeItem $item): ComponentInputsMapping {
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
   * Writes translations back into a nested component input value.
   *
   * Unlike the SDC/JS path (where each prop maps 1:1 to a field item and the
   * field item itself can be set directly), component sources not using
   * PropSources can have translatables at arbitrary nesting depth within a
   * single input key — e.g. `deeply_nested_translatable[0][bar]`. There is no
   * field item to delegate to, so translations must be written back into the
   * raw $inputs array at the exact nested position they were extracted from.
   *
   * Recursively walks the translation-data tree: at translatable leaves
   * (#translate TRUE), returns the #translation['#text'] value; at intermediate
   * nodes, merges translated children back into the existing input array,
   * preserving non-translated sibling keys.
   *
   * @param array $translation_data
   *   Translation-data node, e.g.
   *   ['#text' => ..., '#translation' => [...], ...].
   * @param mixed $existing
   *   Existing input value to merge translated children into.
   * @param bool $found
   *   Set to TRUE if at least one #translation was applied.
   *
   * @return mixed
   *   The updated value with translations written in at the correct depth.
   */
  private static function writeNestedTranslationsToInputs(array $translation_data, mixed $existing, bool &$found): mixed {
    if (\array_key_exists('#translate', $translation_data) && $translation_data['#translate'] === TRUE) {
      \assert(\array_key_exists('#translation', $translation_data));
      \assert(\array_key_exists('#text', $translation_data['#translation']));
      $found = TRUE;
      return $translation_data['#translation']['#text'];
    }
    $result = \is_array($existing) ? $existing : [];
    foreach (Element::children($translation_data) as $key) {
      $result[$key] = self::writeNestedTranslationsToInputs($translation_data[$key], $result[$key] ?? NULL, $found);
    }
    return $result;
  }

}
