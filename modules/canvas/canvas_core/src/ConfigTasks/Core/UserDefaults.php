<?php

/**
 * @file
 * Contains a UserDefaults ConfigTask
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\ConfigTasks\Core;

use Drupal\canvas\ConfigTasks\ConfigTaskInterface;
use Drupal\canvas\ConfigTasks\Variable;

/**
 * Class UserDefaults
 * @package Drupal\canvas_core\ConfigTasks\Core
 */
class UserDefaults extends Variable implements ConfigTaskInterface {

  /**
   * Fetch the configuration parameters.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig() {
    return array(
      'user_register' => USER_REGISTER_ADMINISTRATORS_ONLY,
      'admin_theme' => CANVAS_UTILITIES_DEFAULT_ADMIN_THEME,
      'node_admin_theme' => '1',
    );
  }

}
