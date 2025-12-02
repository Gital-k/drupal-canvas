<?php

declare(strict_types=1);

namespace Drupal\canvas_layout_builder_test\Plugin\Layout;

use Drupal\Core\Layout\Attribute\Layout;
use Drupal\Core\Layout\LayoutDefault;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\system\Entity\Menu;

/**
 * Layout Builder Test Layout.
 */
#[Layout(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Layout Builder Test Plugin'),
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
class TestLayout extends LayoutDefault {

  const PLUGIN_ID = 'canvas_layout_builder_test_layout';

  const MAIN_REGION = 'main';
  const SIDEBAR_REGION = 'sidebar';

  /**
   * {@inheritdoc}
   */
  public function build(array $regions) {
    $build = parent::build($regions);
    $build[self::MAIN_REGION]['#attributes']['class'][] = 'canvas-main-test';
    if ($this->inPreview) {
      $build[self::MAIN_REGION]['#attributes']['class'][] = 'canvas-main-test-preview';
    }
    $build[self::SIDEBAR_REGION]['#attributes']['class'][] = 'canvas-sidebar-test';
    if ($this->inPreview) {
      $build[self::SIDEBAR_REGION]['#attributes']['class'][] = 'canvas-sidebar-test-preview';
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
