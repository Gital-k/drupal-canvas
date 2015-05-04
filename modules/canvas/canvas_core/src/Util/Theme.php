<?php

/**
 * @file
 * Contains a
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\Util;


/**
 * Class SetTheme
 * @package Drupal\canvas_core\Util
 */
class Theme {

  /**
   * Set the theme at any point.
   *
   * @param string $theme_name
   *   Name of the theme to set.
   *
   * @static
   */
  static public function set($theme_name) {
    if ($GLOBALS['theme'] != $theme_name) {
      unset($GLOBALS['theme']);

      drupal_static_reset();
      $GLOBALS['conf']['maintenance_theme'] = $theme_name;
      _drupal_maintenance_theme();
    }
  }
}
