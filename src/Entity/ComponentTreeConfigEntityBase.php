<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * @internal
 *
 * @phpstan-import-type ComponentTreeItemArray from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
 */
abstract class ComponentTreeConfigEntityBase extends ConfigEntityBase implements ComponentTreeEntityInterface {

  use ComponentTreeItemListInstantiatorTrait;

  /**
   * The component tree.
   *
   * @var ?array<string, ComponentTreeItemArray>
   */
  protected ?array $component_tree;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values): void {
    // Resolve null component_version values to the component's current active
    // version. This allows recipe authors to ship environment-agnostic config
    // by omitting the version hash, which encodes environment-specific state
    // such as the installed set of image media types.
    // @see https://www.drupal.org/project/canvas/issues/3571366
    foreach ($values['component_tree'] ?? [] as $delta => $item) {
      if (($item['component_version'] ?? NULL) === NULL && isset($item['component_id'])) {
        $component = Component::load($item['component_id']);
        if ($component !== NULL) {
          $values['component_tree'][$delta]['component_version'] = $component->getActiveVersion();
        }
      }
    }
    parent::preCreate($storage, $values);
  }

  public function setComponentTree(array $values): static {
    $this->set('component_tree', $values);
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Works around Typed Data static caching bugs in core's EntityBase, remove after https://www.drupal.org/project/drupal/issues/3571532 is fixed.
   */
  public function createDuplicate() {
    $duplicate = parent::createDuplicate();
    unset($duplicate->typedData);
    return $duplicate;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Works around Typed Data static caching bugs in core's EntityBase, remove after https://www.drupal.org/project/drupal/issues/3571532 is fixed.
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    unset($this->typedData);
    parent::postSave($storage, $update);
  }

}
