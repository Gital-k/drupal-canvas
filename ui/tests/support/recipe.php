<?php

$drupal_site_path = getenv('DRUPAL_DEV_SITE_PATH');
$recipe = getenv('RECIPE');
$root = getenv('DRUPAL_ROOT');

if (!empty($root) && !empty($recipe) && !empty($drupal_site_path) && chdir(getenv('DRUPAL_ROOT'))) {
  passthru("DRUPAL_DEV_SITE_PATH=$drupal_site_path php core/scripts/drupal recipe $recipe");
}
