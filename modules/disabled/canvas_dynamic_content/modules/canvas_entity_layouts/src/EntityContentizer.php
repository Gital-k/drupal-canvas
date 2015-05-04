<?php

/**
 * @file
 * Contains a EntityContentizer
 */

namespace Drupal\canvas_entity_layouts;


/**
 * Class EntityContentizer
 * @package Drupal\canvas_entity_layouts
 */
class EntityContentizer {

  /**
   * Defines the layout field name.
   */
  const FIELD_LAYOUT = 'field_layout_mode';

  /**
   * Defines the paragraphs field name.
   */
  const FIELD_PARAGRAPHS = 'field_entity_content';

  /**
   * Callback for hook_entity_view().
   *
   * @param object $entity
   *   The entity.
   * @param string $entity_type
   *   The entity type.
   * @param string $view_mode
   *   The view mode.
   * @param string $langcode
   *   The langcode
   */
  public static function entityView($entity, $entity_type, $view_mode, $langcode) {
    if ($view_mode === 'full') {
      $layout_lang = static::fieldLanguage($entity_type, $entity, static::FIELD_LAYOUT, $langcode);
      if (!empty($entity->{static::FIELD_LAYOUT}[$layout_lang][0]['value'])) {
        // The layout field switches content between normal and paragraphs
        // (dynamic) mode.
        if ($entity->{static::FIELD_LAYOUT}[$layout_lang][0]['value'] === canvas_ENTITY_LAYOUTS_MODE_DYNAMIC) {
          static::processParagraphsEntity($entity, $entity_type, $langcode);
        }
        else {
          // The paragraphs field should not appear in normal mode.
          unset($entity->content[self::FIELD_PARAGRAPHS]);
        }
      }
    }
  }

  /**
   * Process the Paragraphs entity.
   *
   * @param object $entity
   *   The entity.
   * @param string $entity_type
   *   The entity type.
   * @param string $langcode
   *   The langcode
   */
  protected static function processParagraphsEntity($entity, $entity_type, $langcode) {
    $content = $entity->content;
    // Replace the entity content with the content of the paragraphs
    // field.
    $entity->content = field_view_field($entity_type, $entity, self::FIELD_PARAGRAPHS, array('label' => 'hidden'), $langcode);

    // If there is an 'entity_content' paragraph item, replace its content
    // with the current entity.
    if (isset($entity->content['#items'])) {
      foreach ($entity->content['#items'] as $paragraphs_index) {
        $paragraphs_index = $paragraphs_index['value'];
        foreach ($entity->content as $content_row_id => $content_row) {
          if (!is_array($content_row)) {
            continue;
          }
          if (isset($content_row['entity']['paragraphs_item'][$paragraphs_index]) && $content_row['entity']['paragraphs_item'][$paragraphs_index]['#bundle'] === 'entity_content') {
            $entity->content[$content_row_id]['entity']['paragraphs_item'][$paragraphs_index]['content'] = $content;
          }
        }
      }
    }
  }

  /**
   * Get the correct language for a field.
   *
   * @param string $entity_type
   *   The entity type.
   * @param object $entity
   *   The entity
   * @param string $field_name
   *   The field name
   * @param string $langcode
   *   The language code
   *
   * @return string
   *   The language code.
   */
  protected static function fieldLanguage($entity_type, $entity, $field_name, $langcode) {
    return field_language($entity_type, $entity, $field_name, $langcode);
  }
}
