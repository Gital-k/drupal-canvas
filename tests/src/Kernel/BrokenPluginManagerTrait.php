<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

/**
 * Hides plugins marked as missing while the broken-components flag is set.
 *
 * Implements the marking and filtering; the using plugin manager only has to
 * route its discovery through ::removeBrokenPlugins().
 */
trait BrokenPluginManagerTrait {

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
