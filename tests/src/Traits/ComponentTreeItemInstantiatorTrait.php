<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Traits;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Builds a Canvas component tree item carrying a single component.
 */
trait ComponentTreeItemInstantiatorTrait {

  /**
   * Instantiates a Canvas component tree item.
   */
  private function buildComponentTreeItem(string $component_id, array $inputs, ?FieldableEntityInterface $root_entity = NULL): ComponentTreeItem {
    $item_list = $root_entity !== NULL
      ? ComponentTreeItemList::createForEntity($this->container->get('typed_data_manager'), $root_entity)
      : ComponentTreeItemList::createHostless($this->container->get('typed_data_manager'));
    $uuid = $this->container->get('uuid')->generate();
    $item_list->setValue([
      [
        'uuid' => $uuid,
        'component_id' => $component_id,
        'inputs' => $inputs,
      ],
    ]);
    $item = $item_list->get(0);
    \assert($item instanceof ComponentTreeItem);
    return $item;
  }

}
