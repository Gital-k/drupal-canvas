<?php
/**
 * @file
 * The canvas install profile file.
 */

/**
 * Implements hook_install_configure_form_alter().
 *
 * Changes the inserted variables on the installer to some different defaults
 */
function canvas_form_install_configure_form_alter(&$form, &$form_state) {
  $form['site_information']['site_name']['#default_value'] = 'canvas';
  $form['site_information']['site_mail']['#default_value'] = 'admin@' . $_SERVER['HTTP_HOST'];
  $form['admin_account']['account']['name']['#default_value'] = 'admin';
  $form['admin_account']['account']['mail']['#default_value'] = 'admin@' . $_SERVER['HTTP_HOST'];
}
