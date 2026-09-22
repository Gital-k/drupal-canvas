<?php

declare(strict_types=1);

namespace Drupal\canvas\ContentTranslation;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\content_translation\FieldTranslationSynchronizerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Decorates field translation synchronizer to sync component instance inputs.
 *
 * After core's synchronizer handles tree-structure synchronization (the 'tree'
 * column group: uuid, parent_uuid, slot, component_id, component_version),
 * this decorator additionally synchronizes non-translatable input keys within
 * the 'inputs' JSON property across all translations.
 *
 * @see \Drupal\content_translation\FieldTranslationSynchronizer
 * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem
 */
final class ComponentTreeFieldSymmetricalTranslationSynchronizer implements FieldTranslationSynchronizerInterface {

  public function __construct(
    private readonly FieldTranslationSynchronizerInterface $decorated,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function synchronizeFields(ContentEntityInterface $entity, $sync_langcode, $original_langcode = NULL): void {
    $pre_sync = $this->captureComponentInstanceStateByUuid($entity);
    $this->decorated->synchronizeFields($entity, $sync_langcode, $original_langcode);
    $this->synchronizeComponentInstanceInputs($entity, $pre_sync);
  }

  /**
   * {@inheritdoc}
   */
  public function synchronizeItems(array &$field_values, array $unchanged_items, $sync_langcode, array $translations, array $properties): void {
    $this->decorated->synchronizeItems($field_values, $unchanged_items, $sync_langcode, $translations, $properties);
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldSynchronizedProperties(FieldDefinitionInterface $field_definition): array {
    return $this->decorated->getFieldSynchronizedProperties($field_definition);
  }

  /**
   * Rebuilds each translation's component instance inputs and labels.
   *
   * After core's synchronizer aligns tree structure (same UUIDs, same order),
   * this restores every non-default translation's translatable data from that
   * instance's own pre-synchronization state, looked up BY UUID, and takes
   * everything else from the default translation.
   *
   * Looking values up by UUID rather than by delta is what makes this correct
   * across structural edits. Core pairs items using a hash of the
   * *synchronized* properties, which for a component tree include
   * `parent_uuid` and `slot`. Re-parenting an instance therefore changes that
   * hash, so core treats a long-lived instance as newly created and merges it
   * by delta with whatever the translation happened to hold in that position
   * - an unrelated component instance's inputs and label.
   *
   * Rebuilding rather than merging is equally load-bearing. The previous
   * implementation merged, and a merge can only ever ADD keys: a foreign key
   * that landed once could never be removed, and every later validation of
   * that translation failed with "the `X` prop is not defined". Rebuilding
   * also drops residue left behind when an instance stops using a prop.
   *
   * @param array<string, array<string, array<string, array{inputs: array, label: ?string, translatable_keys: array<string>}>>> $pre_sync
   *   Per-instance state captured before core's synchronizer ran, keyed by
   *   field name, then langcode, then component instance UUID.
   *
   * @see \Drupal\content_translation\FieldTranslationSynchronizer::synchronizeItems()
   * @see \Drupal\content_translation\FieldTranslationSynchronizer::createMergedItem()
   */
  private function synchronizeComponentInstanceInputs(ContentEntityInterface $entity, array $pre_sync): void {
    foreach ($this->allComponentTreeSymmetricalTranslations($entity) as $target_tree) {
      $field_name = $target_tree->getFieldDefinition()->getName();
      $langcode = $target_tree->getEntity()->language()->getId();
      $default_tree = $entity->getUntranslated()->get($field_name);
      \assert($default_tree instanceof ComponentTreeItemList);

      $default_items = [];
      foreach ($default_tree as $default_item) {
        \assert($default_item instanceof ComponentTreeItem);
        $default_items[$default_item->getUuid()] = $default_item;
      }

      foreach ($target_tree as $target_item) {
        \assert($target_item instanceof ComponentTreeItem);
        // Pair by UUID, not by delta: core's wholesale-copy branch can leave
        // the two lists misaligned.
        $target_uuid = $target_item->getUuid();
        $default_item = $default_items[$target_uuid] ?? NULL;
        if ($default_item === NULL) {
          continue;
        }
        $default_inputs = $default_item->getInputs() ?? [];

        $inputs_typed_data = $default_item->get('inputs');
        \assert($inputs_typed_data instanceof ComponentInputs);
        $translatable_keys = \array_flip($inputs_typed_data->getTranslatableInputKeys());

        // This instance's own pre-synchronization state. Absent when the
        // instance is new in the default translation, which then supplies
        // every value - the case the previous `$net_new` branch handled.
        $own = $pre_sync[$field_name][$langcode][$target_uuid] ?? NULL;
        $own_inputs = $own['inputs'] ?? [];

        // Keys this translation already had, in the order it already had
        // them, so a rebuild is not also a reordering. A translatable prop
        // the default translation leaves unset but this one sets survives;
        // only keys that were translatable for THIS instance before
        // synchronization qualify, so another component's residue cannot
        // re-enter here.
        $own_translatable = \array_flip($own['translatable_keys'] ?? []);
        $rebuilt = [];
        foreach ($own_inputs as $key => $own_value) {
          if (\array_key_exists($key, $default_inputs)) {
            $rebuilt[$key] = \array_key_exists($key, $translatable_keys)
              ? $own_value
              : $default_inputs[$key];
          }
          elseif (\array_key_exists($key, $own_translatable)) {
            $rebuilt[$key] = $own_value;
          }
        }
        // Then whatever only the default translation has, in its order.
        foreach ($default_inputs as $key => $default_value) {
          if (!\array_key_exists($key, $rebuilt)) {
            $rebuilt[$key] = $default_value;
          }
        }
        $target_item->setInput($rebuilt);

        // `label` is translatable too, and core merged it by delta alongside
        // the inputs, so it needs restoring from the same by-UUID state.
        $target_item->setLabel($own === NULL ? $default_item->getLabel() : $own['label']);
      }
    }
  }

  /**
   * Captures each non-default translation's per-instance translatable state.
   *
   * Must be called BEFORE the decorated synchronizer runs. Afterwards the
   * translation's items have already been re-aligned by delta, and the
   * association between a component instance and its own inputs, label and
   * translatable-key set is precisely what has been lost.
   *
   * The translatable-key set is captured here rather than derived later
   * because ComponentInputs::getTranslatableInputKeys() resolves the config
   * schema mapping against the item's *actual* inputs - which, after
   * synchronization, may be another instance's.
   *
   * @return array<string, array<string, array<string, array{inputs: array, label: ?string, translatable_keys: array<string>}>>>
   *   Keyed by field name, then langcode, then component instance UUID.
   */
  private function captureComponentInstanceStateByUuid(ContentEntityInterface $entity): array {
    $captured = [];
    foreach ($this->allComponentTreeSymmetricalTranslations($entity) as $target_tree) {
      $langcode = $target_tree->getEntity()->language()->getId();
      foreach ($target_tree as $item) {
        \assert($item instanceof ComponentTreeItem);
        $inputs_typed_data = $item->get('inputs');
        \assert($inputs_typed_data instanceof ComponentInputs);
        $captured[$target_tree->getFieldDefinition()->getName()][$langcode][$item->getUuid()] = [
          'inputs' => $item->getInputs() ?? [],
          'label' => $item->getLabel(),
          'translatable_keys' => $inputs_typed_data->getTranslatableInputKeys(),
        ];
      }
    }
    return $captured;
  }

  /**
   * Yields each non-default translation's symmetrically translated tree.
   *
   * Iterates every component tree field in symmetrical translation mode (tree
   * synced, inputs translatable) and yields each non-default translation's
   * field item list.
   *
   * @return \Generator<int, ComponentTreeItemList>
   */
  private function allComponentTreeSymmetricalTranslations(ContentEntityInterface $entity): \Generator {
    $translations = $entity->getTranslationLanguages();
    if (count($translations) < 2) {
      return;
    }

    $default_translation = $entity->getUntranslated();
    $default_langcode = $default_translation->language()->getId();

    foreach ($default_translation->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition->getType() !== ComponentTreeItem::PLUGIN_ID) {
        continue;
      }
      if (!self::isSymmetricallyTranslated($this->decorated, $field_definition)) {
        continue;
      }

      foreach ($translations as $langcode => $language) {
        if ($langcode === $default_langcode) {
          continue;
        }
        $target_tree = $entity->getTranslation($langcode)->get($field_name);
        \assert($target_tree instanceof ComponentTreeItemList);
        yield $target_tree;
      }
    }
  }

  public static function isTreeSynced(FieldTranslationSynchronizerInterface $synchronizer, FieldDefinitionInterface $field_definition): bool {
    return \in_array('uuid', $synchronizer->getFieldSynchronizedProperties($field_definition), TRUE);
  }

  public static function isInputsSynced(FieldTranslationSynchronizerInterface $synchronizer, FieldDefinitionInterface $field_definition): bool {
    return \in_array('inputs', $synchronizer->getFieldSynchronizedProperties($field_definition), TRUE);
  }

  /**
   * Returns TRUE if the field is in symmetrical translation mode.
   *
   * Symmetrical mode: tree structure synced, inputs translatable.
   *
   * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer::isAsymmetricallyTranslated()
   */
  public static function isSymmetricallyTranslated(FieldTranslationSynchronizerInterface $synchronizer, FieldDefinitionInterface $field_definition): bool {
    return self::isTreeSynced($synchronizer, $field_definition) && !self::isInputsSynced($synchronizer, $field_definition);
  }

  /**
   * Returns TRUE if the field is in asymmetrical translation mode.
   *
   * Asymmetrical mode: both tree structure and inputs are translatable
   * (nothing synced).
   *
   * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer::isSymmetricallyTranslated()
   */
  public static function isAsymmetricallyTranslated(FieldTranslationSynchronizerInterface $synchronizer, FieldDefinitionInterface $field_definition): bool {
    return !self::isTreeSynced($synchronizer, $field_definition) && !self::isInputsSynced($synchronizer, $field_definition);
  }

  /**
   * Forces symmetrical translation of the Canvas Page `components` field.
   *
   * Loads the base field override storing the `translation_sync` third party
   * setting, or creates it, and sets the only supported combination: input
   * values of component instances are translatable, the component tree is
   * shared across languages.
   *
   * This cannot be shipped as `config/optional`: recipes import a module's
   * optional config unconditionally (no dependency gating), so a recipe
   * importing canvas config without content_translation installed would fail
   * validation on the `content_translation` module dependency.
   *
   * Must only be called when the content_translation module is installed.
   *
   * @see \Drupal\canvas\Hook\ContentTranslationHooks::modulesInstalled()
   * @see canvas_post_update_0022_enforce_symmetrical_canvas_page_components_translation()
   * @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3571130
   */
  public static function ensureSymmetricalCanvasPageComponents(): void {
    $entity_field_manager = \Drupal::service(EntityFieldManagerInterface::class);
    \assert($entity_field_manager instanceof EntityFieldManagerInterface);
    $base_field_definitions = $entity_field_manager->getBaseFieldDefinitions(Page::ENTITY_TYPE_ID);
    \assert($base_field_definitions['components'] instanceof BaseFieldDefinition);
    $override = $base_field_definitions['components']->getConfig(Page::ENTITY_TYPE_ID);
    $override->setThirdPartySetting('content_translation', 'translation_sync', [
      'inputs' => 'inputs',
      'tree' => '0',
    ]);
    $override->save();
  }

}
