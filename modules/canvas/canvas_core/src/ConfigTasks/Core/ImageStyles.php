<?php

/**
 * @file
 * Contains a ImageStyles abstract ConfigTask
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas\ConfigTasks;

use Drupal\config\Config;

/**
 * Class ImageStyles
 * @package Drupal\canvas\ConfigTasks
 */
abstract class ImageStyles extends Variable implements ConfigTaskInterface {

  /**
   * Get Config.
   *
   * @return array
   *   The configuration
   */
  public function getConfig() {
    $config = Config::load();

    return $config->getConfig('canvas_core', 'config', 'image_styles.json', CONFIG_PARSER_JSON);
  }
}
