<?php

/**
 * @file
 * Block functions.
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */


/**
 * Create initial block placement for a block which hasn't been used before.
 *
 * @todo Swap this out for Fabricator.
 *
 * @param string $module
 *   The module providing the block
 * @param string $delta
 *   The block delta
 * @param string $theme
 *   (optional) The theme to insert into. Defaults to the current theme
 * @param int|string $region
 *   (optional) The region to insert the block into. Defaults to
 *   BLOCK_REGION_NONE, so a block can be created but not assigned by leaving
 *   this blank.
 * @param int $weight
 *   (optional) The weight of the block. Defaults to 0.
 * @param int $visibility
 *   (optional) The visibility of the block. Defaults to 0.
 * @param string $pages
 *   (optional) The pages to show the block on. Defaults to all.
 * @param string $title
 *   (optional) The title. Defaults to empty. Specify '<none>' to hide.
 *
 * @throws Exception
 * @return bool
 *   TRUE if the block is inserted, or FALSE on an error.
 */
function canvas_core_insert_block($module, $delta, $theme, $region = BLOCK_REGION_NONE, $weight = 0, $visibility = 0, $pages = '', $title = '') {
  if (!isset($theme)) {
    $theme = variable_get('theme_default', NULL);
    if (!isset($theme) || is_null($theme)) {
      return FALSE;
    }
  }
  $block = array(
    'module' => $module,
    'delta' => $delta,
    'theme' => $theme,
    'title' => $title,
    'status' => (int) ($region != BLOCK_REGION_NONE),
    'weight' => (int) $weight,
    'region' => $region,
    'visibility' => $visibility,
    'pages' => $pages,
    'cache' => DRUPAL_NO_CACHE,
  );

  $query = db_insert('block')->fields(
    array(
      'module',
      'delta',
      'theme',
      'title',
      'status',
      'weight',
      'region',
      'visibility',
      'pages',
      'cache',
    )
  );

  // If a theme was specified execute the single value.
  if (is_array($theme)) {
    // Get a list of themes that this block is already assigned to.
    $assigned_themes = db_query('SELECT theme FROM {block} b WHERE b.module = :module AND b.delta = :delta', array(
      ':module' => $module,
      ':delta' => $delta,
    ))->fetchCol();

    // Get a list of the themes canvas supports.
    foreach ($theme as $theme_id) {
      $record = $block;

      // We need to check if the block already exists for this theme.
      if (in_array($theme_id, $assigned_themes)) {
        continue;
      }

      $record['theme'] = $theme_id;
      $query->values($record);
    }
  }
  else {
    $query->values($block);
  }

  $query->execute();

  return TRUE;
}

/**
 * Update block placement for an existing block.
 *
 * @todo Swap this out for Fabricator.
 *
 * @param string $module
 *   The module providing the block
 * @param string $delta
 *   The block delta
 * @param string $theme
 *   (optional) The theme to insert into. Defaults to the current theme
 * @param int|string $region
 *   (optional) The region to insert the block into. Defaults to
 *   BLOCK_REGION_NONE, so a block can be created but not assigned by leaving
 *   this blank.
 * @param int $weight
 *   (optional) The weight of the block. Defaults to 0
 *
 * This is based on block_admin_display_form_submit()
 *
 * @throws Exception
 */
function canvas_core_update_block($module, $delta, $theme = '', $region = BLOCK_REGION_NONE, $weight = 0) {
  $transaction = db_transaction();
  try {
    $status = (int) ($region != BLOCK_REGION_NONE);
    $region = $status ? $region : '';
    db_update('block')
      ->fields(array(
        'status' => $status,
        'weight' => $weight,
        'region' => $region,
      ))
      ->condition('module', $module)
      ->condition('delta', $delta)
      ->condition('theme', $theme)
      ->execute();
  }
  catch (Exception $e) {
    $transaction->rollback();
    watchdog_exception('block', $e);
    throw $e;
  }
  cache_clear_all();
}
