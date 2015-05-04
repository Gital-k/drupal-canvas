<?php

/**
 * @file
 * Contains a DateFormats ConfigTask
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\ConfigTasks\Core;

use Drupal\canvas\ConfigTasks\ConfigTaskInterface;
use Drupal\canvas\ConfigTasks\ConfigTaskBase;

/**
 * Class DateFormats
 * @package Drupal\canvas\ConfigTasks
 */
class DateFormats extends ConfigTaskBase implements ConfigTaskInterface {

  /**
   * Activate the configuration.
   */
  public function doConfig() {

    // Formats.
    $date_formats = $this->getConfig();
    foreach ($date_formats as $type_string => $data) {
      system_date_format_save($data['format']);
      system_date_format_type_save($data['type']);
      variable_set('date_format_' . $type_string, $data['format']['format']);
    }

    // Default short format.
    variable_set('date_format_short', "d/m/Y - g:ia");
  }

  /**
   * Fetch the configuration parameters.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig() {
    $t_function = get_t();

    $date_formats = array(
      // Day Month Year.
      'canvas_month_day_year' => array(
        'format' => array(
          'type' => 'canvas_month_day_year',
          'format' => 'j F Y',
          'locked' => TRUE,
          'is_new' => TRUE,
        ),
        'type' => array(
          'type' => 'canvas_month_day_year',
          'title' => $t_function('Month Day Year Format'),
          'locked' => TRUE,
          'is_new' => TRUE,
        ),
      ),
      // Month Year.
      'canvas_month_year' => array(
        'format' => array(
          'type' => 'canvas_month_year',
          'format' => 'F Y',
          'locked' => TRUE,
          'is_new' => TRUE,
        ),
        'type' => array(
          'type' => 'canvas_month_year',
          'title' => $t_function('Month Year Format'),
          'locked' => TRUE,
          'is_new' => TRUE,
        ),
      ),
      // Year only.
      'canvas_year' => array(
        'format' => array(
          'type' => 'canvas_year',
          'format' => 'Y',
          'locked' => TRUE,
          'is_new' => TRUE,
        ),
        'type' => array(
          'type' => 'canvas_year',
          'title' => $t_function('Year Format'),
          'locked' => TRUE,
          'is_new' => TRUE,
        ),
      ),
    );

    return $date_formats;
  }

}
