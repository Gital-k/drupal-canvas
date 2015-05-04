<?php

/**
 * @file
 * Contains a Variable abstract ConfigTask
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas\ConfigTasks;

use Drupal\config\Config;

/**
 * Class Variable
 * @package Drupal\canvas\ConfigTasks
 */
abstract class Variable extends ConfigTaskBase implements ConfigTaskInterface {

  /**
   * Activate the configuration.
   */
  public function doConfig() {

    $variables = $this->getConfig();

    foreach ($variables as $key => $value) {
      variable_set($key, $value);
    }
  }

  /**
   * Get config.
   *
   * @return mixed
   *   The config.
   */
  public function getConfig() {
    $config = Config::load();

    return $config->getConfig('canvas_core', 'config', 'variables.json', CONFIG_PARSER_JSON);
  }
}
