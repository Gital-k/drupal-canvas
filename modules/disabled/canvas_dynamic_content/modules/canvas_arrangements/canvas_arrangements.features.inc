<?php
/**
 * @file
 * canvas_arrangements.features.inc
 */

/**
 * Implements hook_eck_bundle_info().
 */
function canvas_arrangements_eck_bundle_info() {
  $items = array(
    'arrangement_arrangement' => array(
      'machine_name' => 'arrangement_arrangement',
      'entity_type' => 'arrangement',
      'name' => 'arrangement',
      'label' => 'Arrangement',
    ),
  );
  return $items;
}

/**
 * Implements hook_eck_entity_type_info().
 */
function canvas_arrangements_eck_entity_type_info() {
  $items = array(
    'arrangement' => array(
      'name' => 'arrangement',
      'label' => 'Arrangement',
      'properties' => array(
        'title' => array(
          'label' => 'Title',
          'type' => 'text',
          'behavior' => 'title',
        ),
      ),
    ),
  );
  return $items;
}
