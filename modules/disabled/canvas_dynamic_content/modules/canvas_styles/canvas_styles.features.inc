<?php
/**
 * @file
 * canvas_styles.features.inc
 */

/**
 * Implements hook_eck_bundle_info().
 */
function canvas_styles_eck_bundle_info() {
  $items = array(
    'paragraph_style_paragraph_style' => array(
      'machine_name' => 'paragraph_style_paragraph_style',
      'entity_type' => 'paragraph_style',
      'name' => 'paragraph_style',
      'label' => 'Style',
    ),
  );
  return $items;
}

/**
 * Implements hook_eck_entity_type_info().
 */
function canvas_styles_eck_entity_type_info() {
  $items = array(
    'paragraph_style' => array(
      'name' => 'paragraph_style',
      'label' => 'Style',
      'properties' => array(
        'human_readable_name' => array(
          'label' => 'Human-readable name',
          'type' => 'text',
          'behavior' => 'title',
        ),
      ),
    ),
  );
  return $items;
}
