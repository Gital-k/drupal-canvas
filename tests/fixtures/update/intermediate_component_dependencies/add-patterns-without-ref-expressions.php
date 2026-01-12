<?php

/**
 * @file
 * Adds a canvas pattern WITHOUT reference field expressions.
 *
 * This pattern should NOT be updated by the intermediate dependencies update.
 */

require_once 'common-component-tree.php';

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

$pattern_data = [
  "uuid" => "bbbb1111-2222-4333-8444-555566667777",
  "langcode" => "en",
  "status" => TRUE,
  "dependencies" => COMPONENT_TREE_WITHOUT_REFERENCE_FIELD_PROP_EXPRESSION_DEPENDENCIES,
  "id" => "pattern_without_ref_expressions",
  "label" => "A pattern without reference expressions",
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
    'name' => 'canvas.pattern.pattern_without_ref_expressions',
    'data' => serialize($pattern_data),
  ])
  ->execute();
