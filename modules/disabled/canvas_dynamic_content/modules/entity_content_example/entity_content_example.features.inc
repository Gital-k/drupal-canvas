<?php
/**
 * @file
 * entity_content_example.features.inc
 */

/**
 * Implements hook_ctools_plugin_api().
 */
function entity_content_example_ctools_plugin_api($module = NULL, $api = NULL) {
  if ($module == "strongarm" && $api == "strongarm") {
    return array("version" => "1");
  }
}

/**
 * Implements hook_node_info().
 */
function entity_content_example_node_info() {
  $items = array(
    'entity_content_example' => array(
      'name' => t('Entity Content example'),
      'base' => 'node_content',
      'description' => t('Provides an example content type showing you how to set up dynamic Entity Content for existing content types.'),
      'has_title' => '1',
      'title_label' => t('Title'),
      'help' => '',
    ),
  );
  drupal_alter('node_info', $items);
  return $items;
}
