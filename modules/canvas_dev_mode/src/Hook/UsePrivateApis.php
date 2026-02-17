<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_mode\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;

/**
 * @file
 * Hook implementations that make private APIs available ahead of being ready.
 *
 * ⚠️ Installing this module and developing against private (alpha) APIs does
 * mean you agree to chasing API changes until they become public!
 *
 * This module also feature-flags array type support for JSON Schema props.
 * The 'array' type and canvas.json_schema.prop.array definition are
 * dynamically added via hook_config_schema_info_alter() to prevent side
 * effects when the feature is disabled. Once stable, these will be moved to
 * canvas.json_schema.yml.
 */
readonly final class UsePrivateApis {

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter', order: Order::Last)]
  public function configSchemaInfoAlter(array &$definitions): void {
    // Allow any ComponentSource plugin to be used.
    // @see \Drupal\canvas\ComponentSource\ComponentSourceInterface
    // @todo Remove this constraint after https://www.drupal.org/i/3520484#stable is done.
    unset($definitions['canvas.component.*']['mapping']['source']['constraints']['Choice']);

    // Add array type to allowed choices.
    // This is a feature flag for array support.
    // The canvas.json_schema.prop.array definition exists in the base schema,
    // but 'array' is only added to allowed types when this module is enabled.
    $this->addArrayTypeChoice($definitions);
  }

  /**
   * Adds 'array' to allowed type choices.
   *
   * Note: The canvas.json_schema.prop.array definition must exist in the base
   * schema file. Drupal does not allow hook_config_schema_info_alter() to add
   * new schema definitions, only modify existing ones.
   *
   * @param array &$definitions
   *   The config schema definitions.
   */
  private function addArrayTypeChoice(array &$definitions): void {
    // Add 'array' to the allowed type choices.
    if (isset($definitions['canvas.json_schema.prop.*']['mapping']['type']['constraints']['Choice']['choices'])) {
      $choices = &$definitions['canvas.json_schema.prop.*']['mapping']['type']['constraints']['Choice']['choices'];
      if (!in_array('array', $choices, TRUE)) {
        $choices[] = 'array';
      }
    }
  }

}
