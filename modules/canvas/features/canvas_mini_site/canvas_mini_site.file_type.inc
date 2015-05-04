<?php
/**
 * @file
 * canvas_mini_site.file_type.inc
 */

/**
 * Implements hook_file_default_types().
 */
function canvas_mini_site_file_default_types() {
  $export = array();

  $file_type = new stdClass();
  $file_type->disabled = FALSE; /* Edit this to true to make a default file_type disabled initially */
  $file_type->api_version = 1;
  $file_type->type = 'mini_site_archive';
  $file_type->label = 'Mini-site Archive';
  $file_type->description = 'A mini-site archive';
  $file_type->mimetypes = array();
  $export['mini_site_archive'] = $file_type;

  return $export;
}
