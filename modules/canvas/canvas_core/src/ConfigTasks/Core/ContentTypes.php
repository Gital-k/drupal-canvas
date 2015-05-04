<?php

/**
 * @file
 * Contains a ConfigTaskTemplate
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\ConfigTasks\Core;

use Drupal\canvas\ConfigTasks\ConfigTaskBase;
use Drupal\canvas\ConfigTasks\ConfigTaskInterface;

/**
 * Class ConfigTaskTemplate
 * @package Drupal\canvas_core\ConfigTasks\Core
 */
class ContentTypes extends ConfigTaskBase implements ConfigTaskInterface {

  /**
   * Activate the configuration.
   */
  public function doConfig() {

    // Insert default pre-defined node types into the database. For a complete
    // list of available node type attributes, refer to the node type API
    // documentation at: http://api.drupal.org/api/HEAD/function/hook_node_info.
    $types = $this->getConfig();

    foreach ($types as $type_name => $type_settings) {

      $node_type = node_type_set_defaults($type_settings['settings']);
      node_type_save($node_type);

      if (!empty($type_settings['body'])) {
        node_add_body_field($node_type);
      }

      rdf_mapping_save($type_settings['rdf']);

      // Default "Basic page" to not be promoted and have comments disabled.
      variable_set('node_options_' . $type_name, $type_settings['options']);
      variable_set('comment_' . $type_name, $type_settings['comment']);
      variable_set('node_submitted_' . $type_name, $type_settings['submitted']);
    }
  }

  /**
   * Fetch the configuration parameters.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig() {
    return array(
      array(
        'settings' => array(
          'type' => 'page',
          'name' => st('Simple page'),
          'base' => 'node_content',
          'description' => st("Use <em>simple pages</em> for your static content, such as an 'About us' page. Simple pages contain a basic text editor and have no layout or fields."),
          'custom' => 1,
          'modified' => 1,
          'locked' => 0,
        ),
        'body' => TRUE,
        'submitted' => FALSE,
        'comment' => COMMENT_NODE_HIDDEN,
        'options' => array('status'),
        'rdf' => array(
          'type' => 'node',
          'bundle' => 'page',
          'mapping' => array(
            'rdftype' => array('foaf:Document'),
          ),
        ),
      ),
    );
  }

}
