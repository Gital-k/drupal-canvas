<?php

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * @file
 * Hook implementations for Trash module compatibility functionality.
 */
class TrashHooks {

  public function __construct(private readonly AutoSaveManager $autoSaveManager) {
  }

  /**
   * Implements entity_trash_restore().
   *
   * If a trashed entity is restored, update the changed time of its auto-saved
   * version to match the restored entity or Drupal will not allow saving it.
   *
   * @todo This is actually true for any of save of an entity, not just restore
   *  but for pages we do not allow saving the entities outside of the Canvas
   *  editor so we only need to handle this case for now.
   *
   * @see \Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraintValidator
   */
  #[Hook('entity_trash_restore')]
  public function entityTrashRestore(EntityInterface $entity): void {
    if (!$entity instanceof EntityChangedInterface) {
      return;
    }
    $autoSave = $this->autoSaveManager->getAutoSaveEntity($entity);
    if (!$autoSave->isEmpty()) {
      $autoSaveEntity = $autoSave->entity;
      \assert($autoSaveEntity instanceof EntityChangedInterface);
      $autoSaveEntity->setChangedTime($entity->getChangedTime());
      $this->autoSaveManager->saveEntity($autoSaveEntity);
    }
  }
}
