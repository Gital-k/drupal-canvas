<?php
/**
 * @file
 * canvas_entity_layouts.features.field_base.inc
 */

/**
 * Implements hook_field_default_field_bases().
 */
function canvas_entity_layouts_field_default_field_bases() {
  $field_bases = array();

  // Exported field_base: 'field_layout_mode'
  $field_bases['field_layout_mode'] = array(
    'active' => 1,
    'cardinality' => 1,
    'deleted' => 0,
    'entity_types' => array(),
    'field_name' => 'field_layout_mode',
    'foreign keys' => array(),
    'indexes' => array(
      'value' => array(
        0 => 'value',
      ),
    ),
    'locked' => 0,
    'module' => 'list',
    'settings' => array(
      'allowed_values' => array(),
      'allowed_values_function' => 'canvas_entity_layouts_layout_mode_options',
    ),
    'translatable' => 0,
    'type' => 'list_text',
  );

  // Exported field_base: 'field_paragraph_content'
  $field_bases['field_paragraph_content'] = array(
    'active' => 1,
    'cardinality' => -1,
    'deleted' => 0,
    'entity_types' => array(),
    'field_name' => 'field_entity_content',
    'foreign keys' => array(),
    'indexes' => array(),
    'locked' => 0,
    'module' => 'paragraphs',
    'settings' => array(),
    'translatable' => 0,
    'type' => 'paragraphs',
  );

  return $field_bases;
}
