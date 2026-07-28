<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Tmgmt\ComponentInputsConfigProcessor;
use Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor;
use Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\tmgmt\JobItemInterface;

/**
 * Hook implementations for TMGMT integration.
 */
final readonly class TmgmtHooks {

  public function __construct(
    private ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter')]
  public static function configSchemaInfoAlter(array &$definitions): void {
    // 'canvas.pattern.*' is intentionally left out of this list as patterns are
    // not translatable.
    $types_with_component_trees = [
      'canvas.content_template.*.*.*',
      'canvas.page_variant.*',
    ];
    foreach ($types_with_component_trees as $types_with_component_tree) {
      if (isset($definitions[$types_with_component_tree])) {
        $definitions[$types_with_component_tree]['tmgmt_config_processor'] = ComponentInputsConfigProcessor::class;
      }
    }
  }

  /**
   * Implements hook_field_info_alter().
   *
   * Registers ComponentTreeFieldProcessor for component_tree fields when
   * tmgmt_content is enabled, so each translatable prop in `inputs` appears as
   * a separate translatable string in the TMGMT review UI.
   *
   * @todo Refactor to use `HookDependsOnModule` once Canvas depends on Drupal 11.5
   * @see https://www.drupal.org/node/3548805
   */
  #[Hook('field_info_alter')]
  public function fieldInfoAlter(array &$info): void {
    if (isset($info[ComponentTreeItem::PLUGIN_ID]) && $this->moduleHandler->moduleExists('tmgmt_content')) {
      $info[ComponentTreeItem::PLUGIN_ID]['tmgmt_field_processor'] = ComponentTreeFieldProcessor::class;
    }
  }

  /**
   * Implements hook_tmgmt_data_item_text_output_alter().
   *
   * Blanks the ∅ sentinel out of both review form columns. The sentinel is an
   * internal marker only (TMGMT's Data::flatten() strips empty strings, so
   * extraction must produce a non-empty #text for empty source inputs); it
   * must never be shown to translators. With an empty $source_text, TMGMT
   * builds no source textarea at all, and formTmgmtJobItemEditFormAlter()
   * replaces it with an explanatory message.
   *
   * @param string $source_text
   *   The source text to display, passed by reference.
   * @param string $translation_text
   *   The translation text to display, passed by reference.
   * @param array<string, mixed> $context
   *   Context including 'data_item' and 'job_item'.
   *
   * @see \Drupal\tmgmt\Form\JobItemForm::buildSource()
   */
  #[Hook('tmgmt_data_item_text_output_alter')]
  public static function tmgmtDataItemTextOutputAlter(string &$source_text, string &$translation_text, array $context): void {
    if (!self::isCanvasPageJobItemContext($context)) {
      return;
    }
    if ($source_text === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL) {
      $source_text = '';
    }
    if ($translation_text === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL) {
      $translation_text = '';
    }
  }

  /**
   * Checks whether the hook context belongs to a Canvas Page content job item.
   *
   * @param array<string, mixed> $context
   *   TMGMT hook context.
   */
  private static function isCanvasPageJobItemContext(array $context): bool {
    $job_item = $context['job_item'] ?? NULL;
    if (!$job_item instanceof JobItemInterface) {
      return FALSE;
    }
    return $job_item->getPlugin() === 'content'
      && $job_item->getItemType() === Page::ENTITY_TYPE_ID;
  }

  /**
   * Implements hook_form_tmgmt_job_item_edit_form_alter().
   *
   * For every component_tree row on the TMGMT review form:
   * - Adds a "Fallback to default translation" checkbox. Checked, the row's
   *   input key is dropped from the translation, so the default translation's
   *   value is rendered. Unchecked, an empty textarea stores an explicitly
   *   empty string and any other text is stored verbatim.
   * - Replaces the (absent) source textarea of inputs that are empty in the
   *   source language with an explanatory message.
   * - Wires validation handlers that convert the checkbox to the internal ∅
   *   sentinel and temporarily mask empty translations so TMGMT's
   *   "The field is empty." validation never fires for component_tree rows.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @see \Drupal\tmgmt\Form\JobItemForm::validateJobItem()
   * @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor::setTranslations()
   */
  #[Hook('form_tmgmt_job_item_edit_form_alter')]
  public static function formTmgmtJobItemEditFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $item = $form_object->getEntity();
    if (!$item instanceof JobItemInterface) {
      return;
    }
    $component_tree_field_names = self::getComponentTreeFieldNames($item);
    if ($component_tree_field_names === []) {
      return;
    }

    $row_paths = self::getComponentTreeRowPaths($form, $component_tree_field_names);
    if ($row_paths === []) {
      return;
    }
    // Persisted for the validate callbacks below; recomputed on every (re)build
    // of the form.
    $form_state->set('canvas_component_tree_row_paths', $row_paths);

    /** @var \Drupal\tmgmt\Data $data_service */
    $data_service = \Drupal::service('tmgmt.data');
    foreach ($row_paths as [$group_key, $parent_key, $field_key]) {
      $row = &$form['review'][$group_key][$parent_key][$field_key];
      $data_item = $item->getData($data_service->ensureArrayKey($field_key));

      // tmgmtDataItemTextOutputAlter() blanked the sentinel, so TMGMT built no
      // source element for this row; show why instead of an empty cell.
      // @see \Drupal\tmgmt\Form\JobItemForm::buildSource()
      if (($data_item['#text'] ?? '') === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL && !isset($row['source'])) {
        $row['source'] = [
          '#type' => 'item',
          '#markup' => t('This field is empty in the source language.'),
        ];
      }

      // Wrap the plain translation textarea so the checkbox renders directly
      // below it, inside the translation column (the row template renders a
      // fixed set of keys, so a sibling key would not be shown). The resulting
      // translation[value] structure matches TMGMT's text_format shape, which
      // ::validateJobItem() and ::save() already handle.
      // @see tmgmt-data-items-form.html.twig
      // @see \Drupal\tmgmt\Form\JobItemForm::buildTranslation()
      if (($row['translation']['#type'] ?? '') === 'textarea') {
        $textarea = $row['translation'];
        // Hide (not disable) the textarea when fallback is checked: a visible
        // but disabled value would suggest it still gets used. The typed text
        // is preserved and reappears when the checkbox is unchecked.
        $textarea['#states']['invisible'] = [
          ':input[name="' . $field_key . '[translation][canvas_fallback]"]' => ['checked' => TRUE],
        ];
        $row['translation'] = [
          'value' => $textarea,
          'canvas_fallback' => [
            '#type' => 'checkbox',
            '#title' => t('Fallback to default translation'),
            // Checked iff fallback was previously saved for this row (the
            // sentinel is stored). An unset or explicitly empty ('') stored
            // translation leaves it unchecked.
            '#default_value' => ($data_item['#translation']['#text'] ?? '') === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL,
          ],
        ];
      }
      unset($row);
    }

    // The accept ("Save as completed") and validate buttons run TMGMT's
    // ::validateJobItem, which errors on empty translations. Mask empty
    // component_tree translations with the sentinel before it runs and unmask
    // afterwards (so a validation error elsewhere on the form never re-renders
    // the sentinel in a textarea). ::validateJobItem reads #value from $form
    // while ::save() reads $form_state values, so the mask is invisible to
    // what gets saved.
    // @see \Drupal\tmgmt\Form\JobItemForm::actions()
    foreach (['accept', 'validate'] as $button) {
      if (isset($form['actions'][$button]['#validate'])) {
        \array_unshift(
          $form['actions'][$button]['#validate'],
          [self::class, 'convertFallbackToSentinel'],
          [self::class, 'maskEmptyComponentTreeTranslations'],
        );
        $form['actions'][$button]['#validate'][] = [self::class, 'unmaskEmptyComponentTreeTranslations'];
      }
    }
    // Buttons without their own #validate (e.g. "Save", the per-row review
    // toggles) run the form-level handlers instead; the checkbox must be
    // honored on those paths too.
    $form['#validate'][] = [self::class, 'convertFallbackToSentinel'];
  }

  /**
   * Form validate callback: converts checked fallback checkboxes to ∅.
   *
   * Overwrites the submitted translation value with the internal sentinel for
   * every component_tree row whose "Fallback to default translation" checkbox
   * is checked. TMGMT then stores the sentinel in the job item data, and
   * ComponentTreeFieldProcessor::setTranslations() drops the input key so the
   * default translation's value is rendered.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function convertFallbackToSentinel(array &$form, FormStateInterface $form_state): void {
    foreach ($form_state->get('canvas_component_tree_row_paths') ?? [] as [, , $field_key]) {
      if (!$form_state->getValue([$field_key, 'translation', 'canvas_fallback'])) {
        continue;
      }
      $form_state->setValue([$field_key, 'translation', 'value'], ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL);
    }
  }

  /**
   * Form validate callback: masks empty component_tree translations with ∅.
   *
   * Runs before TMGMT's ::validateJobItem, which reads the translation from
   * $form[...]['translation']['#value'] and errors on empty values. Empty
   * component_tree translations are valid (explicitly empty, or fallback via
   * the checkbox), so they are temporarily set to the non-empty sentinel.
   * ::save() reads $form_state values, never these #value keys, so the mask
   * cannot leak into saved data.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function maskEmptyComponentTreeTranslations(array &$form, FormStateInterface $form_state): void {
    $masked = [];
    foreach ($form_state->get('canvas_component_tree_row_paths') ?? [] as [$group_key, $parent_key, $field_key]) {
      $translation = &$form['review'][$group_key][$parent_key][$field_key]['translation'];
      // Rich text (text_format) is expanded to translation[value] during form
      // processing; plain text is the textarea itself.
      $value_path = isset($translation['value']) && \is_array($translation['value'])
        ? ['value', '#value']
        : ['#value'];
      $value = NestedArray::getValue($translation, $value_path);
      if ($value === '' || $value === NULL) {
        NestedArray::setValue($translation, $value_path, ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL);
        $masked[] = [$group_key, $parent_key, $field_key, 'translation', ...$value_path];
      }
      unset($translation);
    }
    $form_state->set('canvas_tmgmt_masked_paths', $masked);
  }

  /**
   * Form validate callback: restores masked empty translations.
   *
   * Runs after TMGMT's ::validateJobItem. Without this, a validation error
   * elsewhere on the form (e.g. an empty required field of another type) would
   * re-render the form with the sentinel visible in the masked textareas.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function unmaskEmptyComponentTreeTranslations(array &$form, FormStateInterface $form_state): void {
    foreach ($form_state->get('canvas_tmgmt_masked_paths') ?? [] as $path) {
      NestedArray::setValue($form['review'], $path, '');
    }
    $form_state->set('canvas_tmgmt_masked_paths', []);
  }

  /**
   * Gets the component_tree field names on a content job item's source entity.
   *
   * @param \Drupal\tmgmt\JobItemInterface $item
   *   The TMGMT job item.
   *
   * @return list<string>
   *   The component_tree field names; empty if the job item is not a content
   *   job item or its entity has no component_tree fields.
   */
  private static function getComponentTreeFieldNames(JobItemInterface $item): array {
    if ($item->getPlugin() !== 'content') {
      return [];
    }
    $entity_type_manager = \Drupal::entityTypeManager();
    if (!$entity_type_manager->hasDefinition($item->getItemType())) {
      return [];
    }
    $entity = $entity_type_manager->getStorage($item->getItemType())->load($item->getItemId());
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }
    $field_names = [];
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() === ComponentTreeItem::PLUGIN_ID) {
        $field_names[] = $field_name;
      }
    }
    return $field_names;
  }

  /**
   * Finds the review form rows that belong to component_tree fields.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param list<string> $component_tree_field_names
   *   The component_tree field names on the source entity.
   *
   * @return list<array{string, string, string}>
   *   [group key, parent key, field key] paths under $form['review'], where
   *   the field key is the '|'-joined flattened TMGMT data key (e.g.
   *   "components|0|heading").
   *
   * @see \Drupal\tmgmt\Form\JobItemForm::reviewFormElement()
   */
  private static function getComponentTreeRowPaths(array $form, array $component_tree_field_names): array {
    $paths = [];
    if (!isset($form['review'])) {
      return [];
    }
    foreach (Element::children($form['review']) as $group_key) {
      foreach (Element::children($form['review'][$group_key]) as $parent_key) {
        foreach (Element::children($form['review'][$group_key][$parent_key]) as $field_key) {
          if (!isset($form['review'][$group_key][$parent_key][$field_key]['translation'])) {
            continue;
          }
          foreach ($component_tree_field_names as $field_name) {
            if ($field_key === $field_name || \str_starts_with((string) $field_key, $field_name . '|')) {
              $paths[] = [(string) $group_key, (string) $parent_key, (string) $field_key];
              break;
            }
          }
        }
      }
    }
    return $paths;
  }

}
