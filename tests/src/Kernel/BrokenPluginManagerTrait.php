<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

trait BrokenPluginManagerTrait {

  /**
   * Plugin IDs to hide from the definitions, keyed by plugin ID.
   */
  protected array $missingPlugins = [];

  public function markPluginAsMissing(string $pluginId): void {
    $this->missingPlugins[$pluginId] = $pluginId;
    $this->clearCachedDefinitions();
  }

  protected function removeBrokenPlugins(array $definitions): array {
    if (\Drupal::state()->get('canvas_broken_components')) {
      return \array_diff_key($definitions, $this->missingPlugins);
    }
    return $definitions;
  }

}
