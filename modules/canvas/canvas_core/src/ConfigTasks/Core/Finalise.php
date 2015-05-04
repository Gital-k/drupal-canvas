<?php

/**
 * @file
 * Contains a Finalise ConfigTask
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\ConfigTasks\Core;

use Drupal\canvas\ConfigTasks\ConfigTaskInterface;
use Drupal\canvas\ConfigTasks\ConfigTaskBase;
use Drupal\canvas_core\Util\Features;

/**
 * Class Finalise
 * @package Drupal\canvas\ConfigTasks
 */
class Finalise extends ConfigTaskBase implements ConfigTaskInterface {

  /**
   * Activate the configuration.
   */
  public function doConfig() {

    // Create a Home link in the main menu.
    $item = array(
      'link_title' => st('Home'),
      'link_path' => '<front>',
      'menu_name' => 'main-menu',
      'weight' => -50,
    );
    menu_link_save($item);

    menu_rebuild();

    // Enable xmlsitemap includes for content types.
    $xmlsitemap_settings = array(
      'status' => '1',
      'priority' => '0.5',
    );

    $types = array(
      'node_page',
      'node_blog_article',
      'node_event',
      'node_footer_teaser',
      'node_media_release',
      'node_news_article',
      'node_page',
      'node_publication',
      'node_webform',
    );

    foreach ($types as $type) {
      variable_set('xmlsitemap_settings_' . $type, $xmlsitemap_settings);
    }

    // Set default recipient for contact form.
    db_update('contact')->fields(
      array('recipients' => variable_get('site_mail', 'admin@example.com'))
    )
      ->condition('cid', 1)
      ->execute();

    // Rebuild the sitemap.
    if (module_exists('xmlsitemap')) {
      module_load_include('generate.inc', 'xmlsitemap');

      // Build a list of rebuildable link types.
      $rebuild_types = xmlsitemap_get_rebuildable_link_types();

      // Run the batch process.
      xmlsitemap_run_unprogressive_batch('xmlsitemap_rebuild_batch', $rebuild_types, TRUE);
    }

    // @todo Wysiwyg profiles not being applied on install.
    Features::revertFeatures(array('canvas_editing' => array('wysiwyg')));
  }

  /**
   * Fetch the configuration parameters.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig() {
    // Nothing to do.
  }

}
