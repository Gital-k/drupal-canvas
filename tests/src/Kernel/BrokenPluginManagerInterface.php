<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

/**
 * Plugin manager that can be told to stop discovering a given plugin.
 */
interface BrokenPluginManagerInterface {

  public function markPluginAsMissing(string $pluginId): void;

}
