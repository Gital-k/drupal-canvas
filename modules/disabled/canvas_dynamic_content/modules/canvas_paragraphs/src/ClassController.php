<?php

/**
 * @file
 * Contains a ClassController
 */

namespace Drupal\canvas_paragraphs;


/**
 * Class ClassController
 * @package Drupal\canvas_paragraphs
 */
class ClassController {

  /**
   * Assigns classes to the entity.
   *
   * @param object $entity
   *   The entity object.
   * @param string $type
   *   The type of entity being rendered (i.e. node, user, comment).
   * @param string $view_mode
   *   The view mode the entity is rendered in.
   * @param string $langcode
   *   The language code used for rendering.
   */
  public static function resolveClasses($entity, $type, $view_mode, $langcode) {
    if ($type == 'paragraphs_item') {
      $classes = array();

      // Ensure every paragraph gets a unique identifying class, just in case.
      $classes[] = 'paragraphs-item__' . drupal_html_class($entity->item_id);

      // Process any paragraphs with class settings.
      // These come from the field "field_pbundle_css_classes".
      if (!empty($entity->field_pbundle_css_classes[$langcode][0]['value'])) {
        $classes[] = check_plain($entity->field_pbundle_css_classes[$langcode][0]['value']);
      }

      // Process Paragraphs with Style settings.
      static::processStyleClasses($classes, $entity, $langcode);

      // Process any Paragraphs with layout settings.
      // These come from the field "field_pbundle_container_layout".
      static::processLayoutClasses($classes, $entity, $langcode);

      // Remove duplicate classes.
      $classes = array_keys(array_flip($classes));

      $entity->content['#attributes']['class'][] = implode(' ', $classes);
    }
  }

  /**
   * Process Style entity classes.
   *
   * @param array $classes
   *   Array of CSS classes.
   * @param object $entity
   *   The entity object.
   * @param string $langcode
   *   The language code used for rendering.
   */
  protected static function processStyleClasses(&$classes, $entity, $langcode) {
    if (!empty($entity->field_pbundle_style[$langcode][0])) {
      // Styles are defined by the Style entity.
      $style_entities = entity_load('paragraph_style', array($entity->field_pbundle_style[$langcode][0]['target_id']));
      foreach ($style_entities as $style_entity) {
        if (!empty($style_entity->field_style_classes[$langcode][0]['value'])) {
          foreach ($style_entity->field_style_classes[$langcode] as $style_item) {
            $classes[] = check_plain($style_item['value']);
          }
        }
      }
    }
  }

  /**
   * Process layout entity classes.
   *
   * @param array $classes
   *   Array of CSS classes.
   * @param object $entity
   *   The entity object.
   * @param string $langcode
   *   The language code used for rendering.
   */
  protected static function processLayoutClasses(&$classes, $entity, $langcode) {
    if (!empty($entity->field_pbundle_container_layout[$langcode][0])) {
      // Container arrangements are defined by the Arrangement entity.
      $arrangements = entity_load('arrangement', array($entity->field_pbundle_container_layout[$langcode][0]['target_id']));
      if (empty($arrangements)) {
        return;
      }
      $arrangement = reset($arrangements);

      $layout_class = 'paragraphs-layout__' . drupal_html_class($arrangement->field_machine_name[$langcode][0]['value']);

      // Any other usage should apply to the root element. This may not work
      // in any given case, since the element children may not be the right
      // kind to get the layout classes.
      if ($entity->bundle === 'container') {
        // Containers apply layout to the container_content field.
        $entity->content['field_pbundle_container_content']['#attributes']['class'][] = $layout_class;
      }
      elseif ($entity->bundle === 'view') {
        // Views apply layout to the view field.
        $entity->content['field_pbundle_view']['#attributes']['class'][] = $layout_class;
      }
      else {
        $classes[] = $layout_class;
      }
    }
  }

  /**
   * Set class names on an element.
   *
   * @param array $variables
   *   The array of variables
   * @param string $element_kind
   *   The element kind. Should usually be 'element' or 'elements'.
   */
  public static function setElementClasses(&$variables, $element_kind) {
    if (!empty($variables[$element_kind]['#attributes']['class']) && is_array($variables[$element_kind]['#attributes']['class'])) {
      foreach ($variables[$element_kind]['#attributes']['class'] as $class_name) {
        if (!in_array($class_name, $variables['classes_array'])) {
          $variables['classes_array'][] = $class_name;
        }
      }
    }
  }
}
