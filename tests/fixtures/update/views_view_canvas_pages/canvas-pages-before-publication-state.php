<?php

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Database\Database;

$connection = Database::getConnection();

$yaml_file = __DIR__ . '/views.view.canvas_pages.yml';
$view_config = Yaml::decode(file_get_contents($yaml_file));

$view_config['uuid'] = 'test-canvas-pages-view';

$connection->insert('config')
  ->fields([
    'collection',
    'name',
    'data',
  ])
  ->values([
    'collection' => '',
    'name' => 'views.view.canvas_pages',
    'data' => serialize($view_config),
  ])
  ->execute();
