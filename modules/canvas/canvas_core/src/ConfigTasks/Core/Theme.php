<?php

/**
 * @file
 * Contains a Theme ConfigTask
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\ConfigTasks\Core;

use Drupal\canvas\ConfigTasks\ConfigTaskInterface;
use Drupal\canvas\ConfigTasks\ConfigTaskBase;

/**
 * Class Theme
 * @package Drupal\canvas\ConfigTasks
 */
class Theme extends ConfigTaskBase implements ConfigTaskInterface {

  /**
   * Activate the configuration.
   */
  public function doConfig() {

    // Any themes without keys here will get numeric keys and so will
    // be enabled, but not placed into variables.
    $enable = $this->getConfig();
    theme_enable($enable);

    foreach ($enable as $var => $theme) {
      if (!is_numeric($var)) {
        variable_set($var, $theme);
      }
    }

    // Disable the default Bartik & default admin theme.
    theme_disable(array('bartik', CANVAS_UTILITIES_DEFAULT_ADMIN_THEME));
  }

  /**
   * Fetch the configuration parameters.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig() {
    return array(
      'theme_default' => CANVAS_UTILITIES_DEFAULT_THEME,
      'admin_theme' => CANVAS_UTILITIES_DEFAULT_ADMIN_THEME,
      'install_profile' => 'canvas',
    );
  }

}
