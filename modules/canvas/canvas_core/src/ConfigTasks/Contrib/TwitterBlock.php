<?php

/**
 * @file
 * Contains a TwitterBlock ConfigTask
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas\ConfigTasks\Contrib;

use Drupal\canvas\ConfigTasks\ConfigTaskBase;
use Drupal\canvas\ConfigTasks\ConfigTaskInterface;

/**
 * Class TwitterBlock
 * @package Drupal\canvas\ConfigTasks
 */
class TwitterBlock extends ConfigTaskBase implements ConfigTaskInterface {

  /**
   * Activate the configuration.
   */
  public function doConfig() {

    // Get all the canvas supported themes.
    $themes = canvas_utilities_supported_themes();

    $data = $this->getConfig();

    $bid = db_insert('twitter_block')
      ->fields(array(
        'info' => 'Twitter Feed',
        'widget_id' => CANVAS_UTILITIES_TWITTER_WIDGET_ID,
        'data' => serialize($data),
      ))
      ->execute();

    canvas_core_insert_block('twitter_block', $bid, $themes, 'sidebar_second', -49);
  }

  /**
   * Fetch the configuration parameters.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig() {
    return array(
      "theme" => "",
      "link_color" => "",
      "width" => "",
      "height" => "",
      "chrome" => array(
        "noheader" => "noheader",
        "nofooter" => "nofooter",
        "noborders" => "noborders",
        "noscrollbar" => "noscrollbar",
        "transparent" => "transparent",
      ),
      "border_color" => "",
      "language" => "",
      "tweet_limit" => "",
      "related" => "",
      "polite" => "polite",
    );
  }

}
