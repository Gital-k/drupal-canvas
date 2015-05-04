<?php
/**
 * @file
 * canvas_styles.features.field_instance.inc
 */

/**
 * Implements hook_field_default_field_instances().
 */
function canvas_styles_field_default_field_instances() {
  $field_instances = array();

  // Exported field_instance: 'paragraph_style-paragraph_style-field_style_classes'
  $field_instances['paragraph_style-paragraph_style-field_style_classes'] = array(
    'bundle' => 'paragraph_style',
    'default_value' => NULL,
    'deleted' => 0,
    'description' => 'A list of classes to add to the item.',
    'display' => array(
      'default' => array(
        'label' => 'above',
        'module' => 'text',
        'settings' => array(),
        'type' => 'text_default',
        'weight' => 0,
      ),
      'teaser' => array(
        'label' => 'above',
        'settings' => array(),
        'type' => 'hidden',
        'weight' => 0,
      ),
    ),
    'entity_type' => 'paragraph_style',
    'field_name' => 'field_style_classes',
    'label' => 'Classes',
    'required' => 0,
    'settings' => array(
      'linkit' => array(
        'button_text' => 'Search',
        'enable' => 0,
        'profile' => '',
      ),
      'text_processing' => 0,
      'user_register_form' => FALSE,
    ),
    'widget' => array(
      'active' => 1,
      'module' => 'text',
      'settings' => array(
        'size' => 60,
      ),
      'type' => 'text_textfield',
      'weight' => 0,
    ),
  );

  // Translatables
  // Included for use with string extractors like potx.
  t('A list of classes to add to the item.');
  t('Classes');

  return $field_instances;
}
