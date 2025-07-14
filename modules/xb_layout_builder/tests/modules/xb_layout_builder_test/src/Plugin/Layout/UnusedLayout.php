<?php

declare(strict_types=1);

namespace Drupal\xb_layout_builder_test\Plugin\Layout;

use Drupal\Core\Layout\Attribute\Layout;
use Drupal\Core\Layout\LayoutDefault;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\system\Entity\Menu;

/**
 * Layout Builder Test Layout for testing an unused layout plugin.
 */
#[Layout(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Layout Builder unused test plugin'),
  regions: [
    self::MAIN_REGION => [
      "label" => new TranslatableMarkup("Main Region"),
    ],
    self::SIDEBAR_REGION => [
      "label" => new TranslatableMarkup("Sidebar Region"),
    ],
  ],
  category: new TranslatableMarkup("Layout Builder Test"),
)]
class UnusedLayout extends LayoutDefault {

  const PLUGIN_ID = 'xb_layout_builder_unused_layout';

  const MAIN_REGION = 'main';
  const SIDEBAR_REGION = 'sidebar';

  /**
   * {@inheritdoc}
   */
  public function build(array $regions) {
    $build = parent::build($regions);
    $build[self::MAIN_REGION]['#attributes']['class'][] = 'xb-main-unused-test';
    if ($this->inPreview) {
      $build[self::MAIN_REGION]['#attributes']['class'][] = 'xb-main-unused-preview';
    }
    $build[self::SIDEBAR_REGION]['#attributes']['class'][] = 'xb-sidebar--unused-test';
    if ($this->inPreview) {
      $build[self::SIDEBAR_REGION]['#attributes']['class'][] = 'xb-sidebar-unused-preview';
    }
    return $build;
  }

  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    $menu = Menu::load('menu');
    if (!$menu) {
      // Create a menu if it does not exist.
      $menu = Menu::create([
        'id' => 'menu',
        'label' => 'Menu',
      ]);
      $menu->save();
    }
    $dependencies['config']['system.menu.menu'] = $menu->getConfigDependencyName();
    return $dependencies;
  }

}
