<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Tmgmt\ComponentInputsConfigProcessor;
use Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor;
use Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

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
   * The source column intentionally retains ∅ so translators can see that the
   * original field was empty and they may optionally provide a translation.
   *
   * @param string $source_text
   *   The source text to display, passed by reference (left unchanged).
   * @param string $translation_text
   *   The translation text to display, passed by reference.
   * @param array<string, mixed> $context
   *   Context including 'data_item' and 'job_item'.
   */
  #[Hook('tmgmt_data_item_text_output_alter')]
  public static function tmgmtDataItemTextOutputAlter(string &$source_text, string &$translation_text, array $context): void {
    if ($translation_text === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL) {
      $translation_text = '';
    }
  }

  /**
   * Implements hook_tmgmt_data_item_text_input_alter().
   *
   * Converts the ∅ sentinel back to an empty string before TMGMT stores it
   * as #translation['#text'] in the job item data.
   *
   * @param string $translation_text
   *   The translation text submitted from the form, passed by reference.
   * @param array<string, mixed> $context
   *   Context including 'data_item' (the TMGMT data item) and 'job_item'.
   */
  #[Hook('tmgmt_data_item_text_input_alter')]
  public static function tmgmtDataItemTextInputAlter(string &$translation_text, array $context): void {
    if ($translation_text === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL) {
      $translation_text = '';
    }
  }

  /**
   * Implements hook_form_tmgmt_job_item_edit_form_alter().
   *
   * Appends a validator to the "Publish Translation" (accept) button that
   * clears TMGMT's "The field is empty." errors for component_tree field
   * translations that are intentionally empty.
   *
   * This is scoped strictly to component_tree fields to avoid suppressing
   * legitimate "field is empty" errors on required non-canvas fields (e.g.
   * node title).
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  #[Hook('form_tmgmt_job_item_edit_form_alter')]
  public static function formTmgmtJobItemEditFormAlter(array &$form, FormStateInterface $form_state): void {
    if (!isset($form['actions']['accept'])) {
      return;
    }
    $form['actions']['accept']['#validate'][] = [self::class, 'clearEmptyComponentTreeTranslationErrors'];
  }

  /**
   * Form validate callback: clears "field is empty" errors for component_tree.
   *
   * Runs after TMGMT's ::validateJobItem and clears "The field is empty."
   * errors that belong to component_tree field translations where an empty
   * value is intentional. Errors on other field types (e.g.
   * node title, body) are preserved unchanged.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function clearEmptyComponentTreeTranslationErrors(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->hasAnyErrors()) {
      return;
    }

    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }

    /** @var \Drupal\tmgmt\JobItemInterface $item */
    $item = $form_object->getEntity();

    // Collect the names of all component_tree fields on the source entity so
    // we can limit error clearing strictly to those fields.
    $component_tree_field_names = [];
    if ($item->getPlugin() === 'content') {
      $entity_type_id = $item->getItemType();
      $entity = \Drupal::entityTypeManager()
        ->getStorage($entity_type_id)
        ->load($item->getItemId());
      if ($entity !== NULL) {
        foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
          if ($definition->getType() === ComponentTreeItem::PLUGIN_ID) {
            $component_tree_field_names[] = $field_name;
          }
        }
      }
    }

    if (empty($component_tree_field_names)) {
      return;
    }

    $errors = $form_state->getErrors();
    $form_state->clearErrors();

    foreach ($errors as $element_path => $message) {
      if ((string) $message !== (string) t('The field is empty.')) {
        $form_state->setErrorByName($element_path, $message);
        continue;
      }

      // Error paths from validateJobItem look like:
      // "components|0|heading][translation"
      // Strip "][translation" to get the form values key.
      $field_key = (string) preg_replace('/\]\[translation$/', '', $element_path);

      // Check the key belongs to a component_tree field by matching its prefix
      // against the known component_tree field names on this entity.
      $is_component_tree_field = FALSE;
      foreach ($component_tree_field_names as $field_name) {
        if ($field_key === $field_name || str_starts_with($field_key, $field_name . '|')) {
          $is_component_tree_field = TRUE;
          break;
        }
      }

      if (!$is_component_tree_field) {
        $form_state->setErrorByName($element_path, $message);
        continue;
      }

      // Within component_tree fields, only drop the error when:
      // - Source is the ∅ sentinel: empty optional input; translator
      //   did not provide a value — field stays absent in the translation.
      // - Source has a real non-empty value: translator cleared the
      //   translation — field stays absent so Drupal falls back to source.
      $data_key = \Drupal::service('tmgmt.data')->ensureArrayKey($field_key);
      $data_item = $item->getData($data_key);

      if (!isset($data_item['#text'])) {
        $form_state->setErrorByName($element_path, $message);
        continue;
      }

      $source_text = $data_item['#text'];
      if ($source_text === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL || $source_text !== '') {
        continue;
      }

      $form_state->setErrorByName($element_path, $message);
    }
  }

}
