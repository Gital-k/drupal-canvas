<?php
/**
 * @file
 * canvas_paragraphs.features.inc
 */

/**
 * Implements hook_ctools_plugin_api().
 */
function canvas_paragraphs_ctools_plugin_api($module = NULL, $api = NULL) {
  if ($module == "field_group" && $api == "field_group") {
    return array("version" => "1");
  }
}

/**
 * Implements hook_paragraphs_info().
 */
function canvas_paragraphs_paragraphs_info() {
  $items = array(
    'block' => array(
      'name' => 'Block',
      'bundle' => 'block',
      'locked' => '1',
    ),
    'container' => array(
      'name' => 'Container',
      'bundle' => 'container',
      'locked' => '1',
    ),
    'image' => array(
      'name' => 'Image',
      'bundle' => 'image',
      'locked' => '1',
    ),
    'node' => array(
      'name' => 'Node',
      'bundle' => 'node',
      'locked' => '1',
    ),
    'quick_item' => array(
      'name' => 'Quick item',
      'bundle' => 'quick_item',
      'locked' => '1',
    ),
    'text' => array(
      'name' => 'Text',
      'bundle' => 'text',
      'locked' => '1',
    ),
    'view' => array(
      'name' => 'View',
      'bundle' => 'view',
      'locked' => '1',
    ),
  );
  return $items;
}
