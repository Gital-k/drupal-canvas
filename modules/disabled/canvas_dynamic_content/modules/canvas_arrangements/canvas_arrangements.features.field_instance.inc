<?php
/**
 * @file
 * canvas_arrangements.features.field_instance.inc
 */

/**
 * Implements hook_field_default_field_instances().
 */
function canvas_arrangements_field_default_field_instances() {
  $field_instances = array();

  // Exported field_instance: 'arrangement-arrangement-field_machine_name'
  $field_instances['arrangement-arrangement-field_machine_name'] = array(
    'bundle' => 'arrangement',
    'default_value' => NULL,
    'deleted' => 0,
    'description' => '',
    'display' => array(
      'default' => array(
        'label' => 'above',
        'module' => 'machine_name',
        'settings' => array(),
        'type' => 'machine_name_default',
        'weight' => 0,
      ),
      'teaser' => array(
        'label' => 'above',
        'settings' => array(),
        'type' => 'hidden',
        'weight' => 0,
      ),
    ),
    'entity_type' => 'arrangement',
    'field_name' => 'field_machine_name',
    'label' => 'Machine Name',
    'required' => 1,
    'settings' => array(
      'user_register_form' => FALSE,
    ),
    'widget' => array(
      'active' => 0,
      'module' => 'machine_name',
      'settings' => array(
        'size' => 128,
      ),
      'type' => 'machine_name_default',
      'weight' => 31,
    ),
  );

  // Translatables
  // Included for use with string extractors like potx.
  t('Machine Name');

  return $field_instances;
}
