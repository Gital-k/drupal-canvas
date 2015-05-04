<?php

/**
 * @file
 * Contains a
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas\ConfigTasks;


/**
 * Class ConfigTaskBase
 * @package Drupal\canvas\ConfigTasks
 */
abstract class ConfigTaskBase {

  /**
   * Lazy constructor.
   *
   * @return static
   *   Returns an instance of ConfigTaskInterface
   * @static
   */
  static public function init() {

    return new static();
  }
}
