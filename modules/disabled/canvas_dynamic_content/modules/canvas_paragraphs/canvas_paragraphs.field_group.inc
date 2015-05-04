<?php
/**
 * @file
 * canvas_paragraphs.field_group.inc
 */

/**
 * Implements hook_field_group_info().
 */
function canvas_paragraphs_field_group_info() {
  $export = array();

  $field_group = new stdClass();
  $field_group->disabled = FALSE; /* Edit this to true to make a default field_group disabled initially */
  $field_group->api_version = 1;
  $field_group->identifier = 'group_pbundle_appearance|paragraphs_item|block|form';
  $field_group->group_name = 'group_pbundle_appearance';
  $field_group->entity_type = 'paragraphs_item';
  $field_group->bundle = 'block';
  $field_group->mode = 'form';
  $field_group->parent_name = '';
  $field_group->data = array(
    'label' => 'Appearance',
    'weight' => '1',
    'children' => array(
      0 => 'field_pbundle_css_classes',
      1 => 'field_pbundle_style',
    ),
    'format_type' => 'fieldset',
    'format_settings' => array(
      'label' => 'Appearance',
      'instance_settings' => array(
        'required_fields' => 1,
        'classes' => 'group-pbundle-appearance field-group-fieldset',
        'description' => '',
      ),
      'formatter' => 'collapsed',
    ),
  );
  $export['group_pbundle_appearance|paragraphs_item|block|form'] = $field_group;

  $field_group = new stdClass();
  $field_group->disabled = FALSE; /* Edit this to true to make a default field_group disabled initially */
  $field_group->api_version = 1;
  $field_group->identifier = 'group_pbundle_appearance|paragraphs_item|container|form';
  $field_group->group_name = 'group_pbundle_appearance';
  $field_group->entity_type = 'paragraphs_item';
  $field_group->bundle = 'container';
  $field_group->mode = 'form';
  $field_group->parent_name = '';
  $field_group->data = array(
    'label' => 'Appearance',
    'weight' => '6',
    'children' => array(
      0 => 'field_pbundle_css_classes',
      1 => 'field_pbundle_container_layout',
      2 => 'field_pbundle_style',
    ),
    'format_type' => 'fieldset',
    'format_settings' => array(
      'formatter' => 'collapsible',
      'instance_settings' => array(
        'description' => '',
        'classes' => 'group-pbundle-appearance field-group-fieldset',
        'required_fields' => 1,
      ),
    ),
  );
  $export['group_pbundle_appearance|paragraphs_item|container|form'] = $field_group;

  $field_group = new stdClass();
  $field_group->disabled = FALSE; /* Edit this to true to make a default field_group disabled initially */
  $field_group->api_version = 1;
  $field_group->identifier = 'group_pbundle_appearance|paragraphs_item|image|form';
  $field_group->group_name = 'group_pbundle_appearance';
  $field_group->entity_type = 'paragraphs_item';
  $field_group->bundle = 'image';
  $field_group->mode = 'form';
  $field_group->parent_name = '';
  $field_group->data = array(
    'label' => 'Appearance',
    'weight' => '1',
    'children' => array(
      0 => 'field_pbundle_css_classes',
      1 => 'field_pbundle_style',
    ),
    'format_type' => 'fieldset',
    'format_settings' => array(
      'label' => 'Appearance',
      'instance_settings' => array(
        'required_fields' => 1,
        'classes' => 'group-pbundle-appearance field-group-fieldset',
        'description' => '',
      ),
      'formatter' => 'collapsed',
    ),
  );
  $export['group_pbundle_appearance|paragraphs_item|image|form'] = $field_group;

  $field_group = new stdClass();
  $field_group->disabled = FALSE; /* Edit this to true to make a default field_group disabled initially */
  $field_group->api_version = 1;
  $field_group->identifier = 'group_pbundle_appearance|paragraphs_item|node|form';
  $field_group->group_name = 'group_pbundle_appearance';
  $field_group->entity_type = 'paragraphs_item';
  $field_group->bundle = 'node';
  $field_group->mode = 'form';
  $field_group->parent_name = '';
  $field_group->data = array(
    'label' => 'Appearance',
    'weight' => '1',
    'children' => array(
      0 => 'field_pbundle_css_classes',
      1 => 'field_pbundle_style',
      2 => 'field_pbundle_view_mode',
    ),
    'format_type' => 'fieldset',
    'format_settings' => array(
      'formatter' => 'collapsible',
      'instance_settings' => array(
        'description' => '',
        'classes' => 'group-pbundle-appearance field-group-fieldset',
        'required_fields' => 1,
      ),
    ),
  );
  $export['group_pbundle_appearance|paragraphs_item|node|form'] = $field_group;

  $field_group = new stdClass();
  $field_group->disabled = FALSE; /* Edit this to true to make a default field_group disabled initially */
  $field_group->api_version = 1;
  $field_group->identifier = 'group_pbundle_appearance|paragraphs_item|quick_item|form';
  $field_group->group_name = 'group_pbundle_appearance';
  $field_group->entity_type = 'paragraphs_item';
  $field_group->bundle = 'quick_item';
  $field_group->mode = 'form';
  $field_group->parent_name = '';
  $field_group->data = array(
    'label' => 'Appearance',
    'weight' => '4',
    'children' => array(
      0 => 'field_pbundle_css_classes',
      1 => 'field_pbundle_view_mode',
      2 => 'field_pbundle_style',
    ),
    'format_type' => 'fieldset',
    'format_settings' => array(
      'label' => 'Appearance',
      'instance_settings' => array(
        'required_fields' => 1,
        'classes' => 'group-pbundle-appearance field-group-fieldset',
        'description' => '',
      ),
      'formatter' => 'collapsed',
    ),
  );
  $export['group_pbundle_appearance|paragraphs_item|quick_item|form'] = $field_group;

  $field_group = new stdClass();
  $field_group->disabled = FALSE; /* Edit this to true to make a default field_group disabled initially */
  $field_group->api_version = 1;
  $field_group->identifier = 'group_pbundle_appearance|paragraphs_item|text|form';
  $field_group->group_name = 'group_pbundle_appearance';
  $field_group->entity_type = 'paragraphs_item';
  $field_group->bundle = 'text';
  $field_group->mode = 'form';
  $field_group->parent_name = '';
  $field_group->data = array(
    'label' => 'Appearance',
    'weight' => '1',
    'children' => array(
      0 => 'field_pbundle_css_classes',
      1 => 'field_pbundle_style',
    ),
    'format_type' => 'fieldset',
    'format_settings' => array(
      'label' => 'Appearance',
      'instance_settings' => array(
        'required_fields' => 1,
        'classes' => 'group-pbundle-appearance field-group-fieldset',
        'description' => '',
      ),
      'formatter' => 'collapsed',
    ),
  );
  $export['group_pbundle_appearance|paragraphs_item|text|form'] = $field_group;

  $field_group = new stdClass();
  $field_group->disabled = FALSE; /* Edit this to true to make a default field_group disabled initially */
  $field_group->api_version = 1;
  $field_group->identifier = 'group_pbundle_appearance|paragraphs_item|view|form';
  $field_group->group_name = 'group_pbundle_appearance';
  $field_group->entity_type = 'paragraphs_item';
  $field_group->bundle = 'view';
  $field_group->mode = 'form';
  $field_group->parent_name = '';
  $field_group->data = array(
    'label' => 'Appearance',
    'weight' => '6',
    'children' => array(
      0 => 'field_pbundle_css_classes',
      1 => 'field_pbundle_container_layout',
      2 => 'field_pbundle_style',
    ),
    'format_type' => 'fieldset',
    'format_settings' => array(
      'label' => 'Appearance',
      'instance_settings' => array(
        'required_fields' => 1,
        'classes' => 'group-pbundle-appearance field-group-fieldset',
        'description' => '',
      ),
      'formatter' => 'collapsed',
    ),
  );
  $export['group_pbundle_appearance|paragraphs_item|view|form'] = $field_group;

  return $export;
}
