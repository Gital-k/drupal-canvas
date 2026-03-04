<?php

/**
 * @file
 * Helper to apply recipes through JS tests.
 */

declare(strict_types=1);

$drupal_site_path = getenv('DRUPAL_DEV_SITE_PATH');

if (empty($drupal_site_path)) {
  throw new \Exception('DRUPAL_SITE_PATH not provided!');
}

$recipe = getenv('RECIPE');
if (empty($recipe)) {
  throw new \Exception('RECIPE not provided!');
}

$recipe_path = realpath($recipe);
$root = getenv('DRUPAL_ROOT');
if (empty($root)) {
  throw new \Exception('DRUPAL_ROOT not provided!');
}

if (!chdir($root)) {
  throw new \Exception('Could not change directory from "' . getcwd() . '" to "' . $root);
}

echo shell_exec("DRUPAL_DEV_SITE_PATH=$drupal_site_path php core/scripts/drupal recipe $recipe_path");
