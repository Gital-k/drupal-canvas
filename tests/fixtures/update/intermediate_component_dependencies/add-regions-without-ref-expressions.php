<?php

/**
 * @file
 * Adds a canvas page region WITHOUT reference field expressions.
 *
 * This region should NOT be updated by the intermediate dependencies update.
 */

require_once 'common-component-tree.php';

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

$region_data = [
  "uuid" => "cccc2222-3333-4444-8555-666677778888",
  "langcode" => "en",
  "status" => TRUE,
  "dependencies" => COMPONENT_TREE_WITHOUT_REFERENCE_FIELD_PROP_EXPRESSION_DEPENDENCIES
  + ['theme' => ['stark']],
  "id" => "stark.footer",
  "region" => "footer",
  "theme" => "stark",
  "component_tree" => COMPONENT_TREE_WITHOUT_REFERENCE_FIELD_PROP_EXPRESSION,
];
$connection->insert('config')
  ->fields([
    'collection',
    'name',
    'data',
  ])
  ->values([
    'collection' => '',
    'name' => 'canvas.page_region.stark.footer',
    'data' => serialize($region_data),
  ])
  ->execute();
