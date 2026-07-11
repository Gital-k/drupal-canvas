<?php

namespace Drupal\canvas_stark\Hook;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for canvas_stark.
 */
class CanvasStarkHooks {

  /**
   * Implements hook_form_alter().
   *
   * Forces rendering with Twig for media library forms.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $ml_widget = isset($form['#id']) && str_contains($form['#id'], 'views-exposed-form-media-library-widget');
    $ml_form = str_contains($form_id, 'media');
    if ($ml_widget || $ml_form) {
      _canvas_stark_mark_media_library($form, 'media_library');
    }
  }

  /**
   * Implements hook_theme_suggestions_form_element_label_alter().
   */
  #[Hook('theme_suggestions_form_element_label_alter')]
  public function themeSuggestionsFormElementLabelAlter(array &$suggestions, array $variables): void {
    if (isset($variables['element']['#form_id']) && str_contains($variables['element']['#form_id'], 'media_library')) {
      $suggestions[] = 'form_element_label__media_library';
    }
  }

  /**
   * Implements hook_theme_suggestions_form_element_alter().
   */
  #[Hook('theme_suggestions_form_element_alter')]
  public function themeSuggestionsFormElementAlter(array &$suggestions, array $variables): void {
    if (isset($variables['element']['#form_id']) && str_contains($variables['element']['#form_id'], 'media_library')) {
      $suggestions[] = 'form_element__media_library';
    }
  }

  /**
   * Implements hook_theme_suggestions_form_alter().
   */
  #[Hook('theme_suggestions_form_alter')]
  public function themeSuggestionsFormAlter(array &$suggestions, array $variables): void {
    $ml_view = isset($variables['element']['#id']) && str_contains($variables['element']['#id'], 'views-exposed-form-media-library-widget');
    $ml_form = !empty($variables['element']['#form_id']) && str_contains($variables['element']['#form_id'], 'media_library');
    if ($ml_view || $ml_form) {
      $suggestions[] = 'form__media_library';
    }
  }

  /**
   * Implements hook_theme_suggestions_select_alter().
   */
  #[Hook('theme_suggestions_select_alter')]
  public function themeSuggestionsSelectAlter(array &$suggestions, array $variables): void {
    $ml_view = isset($variables['element']['#id']) && str_contains($variables['element']['#id'], 'views-exposed-form-media-library-widget');
    $ml_form = !empty($variables['element']['#form_id']) && str_contains($variables['element']['#form_id'], 'media_library');
    if ($ml_view || $ml_form) {
      $suggestions[] = 'select__media_library';
    }
    if (isset($variables['element']['#is_multivalue_form']) && $variables['element']['#is_multivalue_form']) {
      $suggestions[] = 'select__multivalue_form';
    }
  }

  /**
   * Implements hook_theme_suggestions_input_alter().
   */
  #[Hook('theme_suggestions_input_alter')]
  public function themeSuggestionsInputAlter(array &$suggestions, array $variables): void {
    $ml_ajax = isset($variables['element']['#ajax']['wrapper']) && $variables['element']['#ajax']['wrapper'] === 'media-library-wrapper';
    $ml_form = isset($variables['element']['#form_id']) && str_contains($variables['element']['#form_id'], 'media_library');
    if ($ml_ajax || $ml_form) {
      $suggestions[] = 'input__media_library';
    }
    if (isset($variables['element']['#is_multivalue_form']) && $variables['element']['#is_multivalue_form']) {
      $type = $variables['element']['#type'] ?? '';
      if ($type === 'submit') {
        $suggestions[] = 'input__submit__multivalue_form';
      }
      else {
        $suggestions[] = 'input__multivalue_form';
      }
    }
  }

  /**
   * Implements hook_theme_suggestions_datetime_wrapper_alter().
   */
  #[Hook('theme_suggestions_datetime_wrapper_alter')]
  public function themeSuggestionsDatetimeWrapperAlter(array &$suggestions, array $variables): void {
    if (isset($variables['element']['#multivalue_field_label']) && !empty($variables['element']['#multivalue_field_label'])) {
      $suggestions[] = 'datetime_wrapper__multivalue_form';
    }
  }

  /**
   * Implements hook_theme_suggestions_alter().
   */
  #[Hook('theme_suggestions_alter')]
  public function themeSuggestionsAlter(array &$suggestions, array $variables): void {
    // Add widget type to theme suggestions.
    if (\in_array($variables["theme_hook_original"], [
      'container',
      'field_multiple_value_form',
      'fieldset',
    ], TRUE) && !empty($variables["element"]["#widget-type"])) {
      if ($variables["theme_hook_original"] === 'fieldset') {
        $suggestions[] = $variables["theme_hook_original"] . '__widget_' . str_replace('-', '_', $variables["element"]["#widget-type"]);
      }
      else {
        $suggestions[] = $variables["theme_hook_original"] . '__' . str_replace('-', '_', $variables["element"]["#widget-type"]);
      }
    }
    elseif (!empty($variables["element"]["#widget-type"])) {
      $suggestions[] = $variables["theme_hook_original"] . '__inwidget_' . str_replace('-', '_', $variables["element"]["#widget-type"]);
    }
  }

  /**
   * Implements hook_preprocess_fieldset__media_library_widget().
   *
   * Passes prop linking data to the label component and component_prop_name to
   * the template. The component_prop_name is used by the DefaultImagePreview
   * component to identify which prop it should manage, enabling the "Remove
   * default" functionality for optional image props with example values.
   *
   * @see \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetCompleteFormAlter()
   * @see ui/src/components/form/components/DefaultImagePreview.tsx
   */
  #[Hook('preprocess_fieldset__media_library_widget')]
  public function preprocessFieldsetMediaLibraryWidget(array &$variables): void {
    // Prop link data is passed to the media library widget differently, as the
    // label is the fieldset legend, not a label element.
    if (isset($variables['element']['#prop_link_data'])) {
      $variables['prop_link_data'] = Json::encode($variables['element']['#prop_link_data']);
    }
    if (isset($variables['element']['#component_prop_name'])) {
      $variables['component_prop_name'] = $variables['element']['#component_prop_name'];
    }
    unset($variables['prefix']['weight_toggle']);
  }

}
