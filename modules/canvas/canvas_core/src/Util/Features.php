<?php

/**
 * @file
 * Contains a Features reverting utility
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\Util;


/**
 * Class Features
 * @package Drupal\canvas_call\Util
 */
class Features {

  /**
   * Revert features
   *
   * @param array $modules
   *   An array of modules to revert.
   *
   * @static
   */
  static public function revertFeatures($modules) {
    module_load_include('inc', 'features', 'features.export');
    features_include();
    foreach ($modules as $module) {
      if (($feature = feature_load($module, TRUE)) && module_exists($module)) {
        $components = array();
        // Forcefully revert all components of a feature.
        foreach (array_keys($feature->info['features']) as $component) {
          if (features_hook($component, 'features_revert')) {
            $components[] = $component;
          }
        }
      }
      if (!empty($components)) {
        foreach ($components as $component) {
          features_revert(array($module => array($component)));
        }
      }
    }
  }
}
