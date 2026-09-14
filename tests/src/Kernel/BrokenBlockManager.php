<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\Core\Block\BlockManager;

/**
 * Block manager that can hide plugins, to simulate an uninstalled provider.
 *
 * Plugins marked as missing disappear from discovery only while the
 * `canvas_broken_components` state flag is set, so one test run can observe
 * both the working and the broken state.
 */
final class BrokenBlockManager extends BlockManager implements BrokenPluginManagerInterface {

  use BrokenPluginManagerTrait;

  public function findDefinitions(): array {
    return $this->removeBrokenPlugins(parent::findDefinitions());
  }

}
