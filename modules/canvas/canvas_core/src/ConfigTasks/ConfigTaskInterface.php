<?php

/**
 * @file
 * Contains a ConfigTaskInterface
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas\ConfigTasks;


/**
 * Class ConfigTaskInterface
 * @package Drupal\canvas\ConfigTasks
 */
interface ConfigTaskInterface {

  /**
   * Lazy constructor.
   *
   * @return static
   *   Returns an instance of ConfigTaskInterface
   * @static
   */
  static public function init();

  /**
   * Activate the configuration.
   */
  public function doConfig();

  /**
   * Fetch the configuration parameters.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig();
}
