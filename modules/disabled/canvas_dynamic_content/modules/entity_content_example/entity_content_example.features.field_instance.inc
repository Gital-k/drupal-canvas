<?php
/**
 * @file
 * entity_content_example.features.field_instance.inc
 */

/**
 * Implements hook_field_default_field_instances().
 */
function entity_content_example_field_default_field_instances() {
  $field_instances = array();

  // Exported field_instance: 'node-entity_content_example-field_entity_content_body'
  $field_instances['node-entity_content_example-field_entity_content_body'] = array(
    'bundle' => 'entity_content_example',
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
    'entity_type' => 'node',
    'field_name' => 'field_entity_content_body',
    'label' => 'Body',
    'required' => 0,
    'settings' => array(
      'linkit' => array(
        'button_text' => 'Search',
        'enable' => 0,
        'profile' => '',
      ),
      'text_processing' => 1,
      'user_register_form' => FALSE,
    ),
    'widget' => array(
      'active' => 1,
      'module' => 'text',
      'settings' => array(
        'rows' => 5,
      ),
      'type' => 'text_textarea',
      'weight' => 1,
    ),
  );

  // Exported field_instance: 'node-entity_content_example-field_entity_content'
  $field_instances['node-entity_content_example-field_entity_content'] = array(
    'bundle' => 'entity_content_example',
    'default_value' => NULL,
    'deleted' => 0,
    'description' => 'The <em>Entity Content</em> widget can be used to display the normal Full View content for this node. For example, this allows you to place new items before and after the normal content.',
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
    'field_name' => 'field_entity_content',
    'label' => 'Entity content',
    'required' => 0,
    'settings' => array(
      'add_mode' => 'select',
      'allowed_bundles' => array(
        'block' => 0,
        'container' => 0,
        'entity_content' => 0,
        'image' => 0,
        'node' => 0,
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
      'weight' => 3,
    ),
  );

  // Exported field_instance: 'node-entity_content_example-field_layout_mode'
  $field_instances['node-entity_content_example-field_layout_mode'] = array(
    'bundle' => 'entity_content_example',
    'default_value' => array(
      0 => array(
        'value' => 'normal',
      ),
    ),
    'deleted' => 0,
    'description' => 'This field changes the display behaviour in Full View mode (i.e. when viewing the item on its own page). In <em>Normal (default)<em> layout mode, the content will be displayed as set in Display Settings. When using <em>Dynamic</em> mode, the content is replaced with the contents of the <strong>Entity Content<strong> field.',
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
        'weight' => 2,
      ),
      'teaser' => array(
        'label' => 'above',
        'settings' => array(),
        'type' => 'hidden',
        'weight' => 0,
      ),
    ),
    'entity_type' => 'node',
    'field_name' => 'field_layout_mode',
    'label' => 'Layout mode',
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
  t('Body');
  t('Entity content');
  t('Layout mode');
  t('The <em>Entity Content</em> widget can be used to display the normal Full View content for this node. For example, this allows you to place new items before and after the normal content.');
  t('This field changes the display behaviour in Full View mode (i.e. when viewing the item on its own page). In <em>Normal (default)<em> layout mode, the content will be displayed as set in Display Settings. When using <em>Dynamic</em> mode, the content is replaced with the contents of the <strong>Entity Content<strong> field.');

  return $field_instances;
}
