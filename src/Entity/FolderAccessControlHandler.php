<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\FolderPermissions;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for folders.
 *
 * Administering folders — creating, renaming, reordering and deleting them —
 * remains reserved to `administer folders`. Viewing a folder that organizes
 * content entities is additionally granted to whoever may organize that
 * content: an editor of the entity type anywhere, or the holder of a permission
 * scoped to this folder. The Pages panel groups pages by folder, so an editor
 * who was served no folder would be shown a flat list of pages that are in fact
 * organized — or worse, a tree missing the folders they cannot see.
 *
 * @see \Drupal\canvas\FolderPermissions
 */
final class FolderAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    \assert($entity instanceof Folder);
    $access = parent::checkAccess($entity, $operation, $account);
    if ($operation !== 'view' || !$entity->targetsContentEntityType()) {
      return $access;
    }
    return $access->orIf(FolderPermissions::checkFolderVisibility($account, $entity));
  }

}
