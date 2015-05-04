<?php
/**
 * @file
 * canvas_mini_site.features.inc
 */

/**
 * Implements hook_ctools_plugin_api().
 */
function canvas_mini_site_ctools_plugin_api($module = NULL, $api = NULL) {
  if ($module == "file_entity" && $api == "file_type") {
    return array("version" => "1");
  }
  if ($module == "strongarm" && $api == "strongarm") {
    return array("version" => "1");
  }
}

/**
 * Implements hook_node_info().
 */
function canvas_mini_site_node_info() {
  $items = array(
    'mini_site' => array(
      'name' => t('Mini-site'),
      'base' => 'node_content',
      'description' => t('A self contained static mini-site'),
      'has_title' => '1',
      'title_label' => t('Title'),
      'help' => '',
    ),
  );
  drupal_alter('node_info', $items);
  return $items;
}
