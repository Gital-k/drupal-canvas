<?php
/**
 * @file
 * canvas_arrangements.features.field_base.inc
 */

/**
 * Implements hook_field_default_field_bases().
 */
function canvas_arrangements_field_default_field_bases() {
  $field_bases = array();

  // Exported field_base: 'field_machine_name'
  $field_bases['field_machine_name'] = array(
    'active' => 1,
    'cardinality' => 1,
    'deleted' => 0,
    'entity_types' => array(),
    'field_name' => 'field_machine_name',
    'foreign keys' => array(),
    'indexes' => array(
      'value' => array(
        0 => 'value',
      ),
    ),
    'locked' => 0,
    'module' => 'machine_name',
    'settings' => array(
      'max_length' => 128,
      'replace' => '_',
      'replace_pattern' => '[^a-z0-9_]+',
    ),
    'translatable' => 0,
    'type' => 'machine_name',
  );

  return $field_bases;
}
