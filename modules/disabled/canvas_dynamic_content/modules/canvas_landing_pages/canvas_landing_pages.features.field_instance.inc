<?php
/**
 * @file
 * canvas_landing_pages.features.field_instance.inc
 */

/**
 * Implements hook_field_default_field_instances().
 */
function canvas_landing_pages_field_default_field_instances() {
  $field_instances = array();

  // Exported field_instance: 'node-landing_page-field_content'
  $field_instances['node-landing_page-field_content'] = array(
    'bundle' => 'landing_page',
    'default_value' => NULL,
    'deleted' => 0,
    'description' => '',
    'display' => array(
      'compact' => array(
        'label' => 'above',
        'settings' => array(),
        'type' => 'hidden',
        'weight' => 0,
      ),
      'default' => array(
        'label' => 'hidden',
        'module' => 'paragraphs',
        'settings' => array(
          'view_mode' => 'full',
        ),
        'type' => 'paragraphs_view',
        'weight' => 0,
      ),
      'teaser' => array(
        'label' => 'above',
        'settings' => array(),
        'type' => 'hidden',
        'weight' => 0,
      ),
    ),
    'entity_type' => 'node',
    'field_name' => 'field_content',
    'label' => 'Content',
    'required' => 0,
    'settings' => array(
      'add_mode' => 'select',
      'allowed_bundles' => array(
        'block' => 0,
        'image' => 0,
        'node' => 0,
        'container' => 0,
        'quick_item' => 0,
        'text' => 0,
        'view' => 0,
      ),
      'default_edit_mode' => 'preview',
      'title' => 'Widget',
      'title_multiple' => 'Widgets',
      'user_register_form' => FALSE,
    ),
    'widget' => array(
      'active' => 0,
      'module' => 'paragraphs',
      'settings' => array(),
      'type' => 'paragraphs_embed',
      'weight' => 1,
    ),
  );

  // Exported field_instance: 'node-landing_page-field_hide_sidebars'
  $field_instances['node-landing_page-field_hide_sidebars'] = array(
    'bundle' => 'landing_page',
    'default_value' => array(
      0 => array(
        'value' => 'none',
      ),
    ),
    'deleted' => 0,
    'description' => 'Hide\'s sidebars for this landing page.',
    'display' => array(
      'compact' => array(
        'label' => 'above',
        'settings' => array(),
        'type' => 'hidden',
        'weight' => 0,
      ),
      'default' => array(
        'label' => 'above',
        'settings' => array(),
        'type' => 'hidden',
        'weight' => 1,
      ),
      'teaser' => array(
        'label' => 'above',
        'settings' => array(),
        'type' => 'hidden',
        'weight' => 0,
      ),
    ),
    'entity_type' => 'node',
    'field_name' => 'field_hide_sidebars',
    'label' => 'Hide Sidebars',
    'required' => 1,
    'settings' => array(
      'user_register_form' => FALSE,
    ),
    'widget' => array(
      'active' => 1,
      'module' => 'options',
      'settings' => array(
        'apply_chosen' => 0,
        'flatten_options' => 0,
        'flatten_sort' => 1,
      ),
      'type' => 'options_select',
      'weight' => 2,
    ),
  );

  // Translatables
  // Included for use with string extractors like potx.
  t('Content');
  t('Hide Sidebars');
  t('Hide\'s sidebars for this landing page.');

  return $field_instances;
}
