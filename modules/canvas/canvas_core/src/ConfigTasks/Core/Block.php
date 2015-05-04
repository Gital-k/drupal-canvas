<?php

/**
 * @file
 * Contains a Block ConfigTask
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\ConfigTasks\Core;

use Drupal\canvas\ConfigTasks\ConfigTaskInterface;
use Drupal\canvas\ConfigTasks\ConfigTaskBase;
use Drupal\canvas\Exception\CanvasInstallerException;

/**
 * Class Block
 * @package Drupal\canvas\ConfigTasks
 */
class Block extends ConfigTaskBase implements ConfigTaskInterface {

  /**
   * Activate the configuration.
   */
  public function doConfig() {

    $results = array();

    $blocks_config = $this->getConfig();

    if (!empty($blocks_config)) {

      // @todo use Fabricator
      module_load_include('inc', 'canvas_core', 'inc/block');

      foreach ($blocks_config as $block_config) {
        foreach ($block_config['theme'] as $theme_key) {
          $results[$block_config['module'] . '_' . $block_config['delta']] = canvas_core_insert_block($block_config['module'], $block_config['delta'], $theme_key, $block_config['region'], $block_config['weight']);
        }
      }
    }

    foreach ($results as $result_key => $result) {
      if ($result == FALSE) {
        $failed[] = $result_key;
      }
    }

    if (!empty($failed)) {
      throw new CanvasInstallerException('Failed to install blocks: ' . implode(', ', $failed));
    }

  }

  /**
   * Fetch the configuration parameters.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig() {

    // Get all the canvas supported themes.
    $themes = canvas_utilities_supported_themes();

    $blocks = array(
      0 => array(
        'module' => '',
        'delta' => '',
        'theme' => $themes,
        'region' => '',
        'weight' => '',
      ),
      // Main content block.
      1 => array(
        'module' => 'system',
        'delta' => 'main',
        'theme' => $themes,
        'region' => 'content',
        'weight' => -12,
      ),
      // Help.
      2 => array(
        'module' => 'system',
        'delta' => 'help',
        'theme' => $themes,
        'region' => 'content',
        'weight' => -14,
      ),
      // Footer menu.
      // @todo Review.
      3 => array(
        'module' => 'menu',
        'delta' => 'menu-footer-sub-menu',
        'theme' => $themes,
        'region' => 'footer',
        'weight' => 3,
      ),
      // Workbench.
      4 => array(
        'module' => 'workbench',
        'delta' => 'block',
        'theme' => $themes,
        'region' => 'content',
        'weight' => -14,
      ),
      // Menu block.
      5 => array(
        'module' => 'menu_block',
        'delta' => 'canvas_menu_block-footer',
        'theme' => $themes,
        'region' => 'footer',
        'weight' => 2,
      ),
      6 => array(
        'module' => 'system',
        'delta' => 'main',
        'theme' => array(CANVAS_UTILITIES_DEFAULT_ADMIN_THEME),
        'region' => 'content',
        'weight' => 0,
      ),
      7 => array(
        'module' => 'system',
        'delta' => 'help',
        'theme' => array(CANVAS_UTILITIES_DEFAULT_ADMIN_THEME),
        'region' => 'help',
        'weight' => 0,
      ),
    );

    return $blocks;
  }

}
