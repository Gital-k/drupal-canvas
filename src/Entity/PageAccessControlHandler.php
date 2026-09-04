<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\FolderPermissions;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

final class PageAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    \assert($entity instanceof Page);
    $access = parent::checkAccess($entity, $operation, $account);

    $access = match ($operation) {
      'view' => $access
        ->orIf(AccessResult::allowedIfHasPermissions(
          $account,
          [Page::CREATE_PERMISSION, Page::EDIT_PERMISSION, Page::DELETE_PERMISSION],
          'OR'
        ))
        ->orIf(
          AccessResult::allowedIf($entity->isPublished())
            ->andIf(AccessResult::allowedIfHasPermission($account, 'access content'))
            ->addCacheableDependency($entity)
        ),
      'update', 'view all revisions', 'view revision', 'revert' => $access->orIf(
        AccessResult::allowedIfHasPermission($account, Page::EDIT_PERMISSION)
      ),
      'delete' => $access->orIf(
        AccessResult::allowedIfHasPermission($account, Page::DELETE_PERMISSION)
      ),
      default => $access,
    };
    if ($access->isAllowed()) {
      return $access;
    }

    // What the site-wide permissions do not grant, the folder the page is filed
    // into may: each folder of pages has its own edit and delete permission.
    // Consulted only when nothing else granted the operation, so that the
    // site-wide case does not pay for depending on where the page is filed.
    // @see \Drupal\canvas\FolderPermissions
    return match ($operation) {
      'view' => $access
        ->orIf(FolderPermissions::checkFolderAccess($account, 'edit', $entity))
        ->orIf(FolderPermissions::checkFolderAccess($account, 'delete', $entity)),
      'update', 'view all revisions', 'view revision', 'revert' => $access->orIf(
        FolderPermissions::checkFolderAccess($account, 'edit', $entity)
      ),
      'delete' => $access->orIf(
        FolderPermissions::checkFolderAccess($account, 'delete', $entity)
      ),
      default => $access,
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, Page::CREATE_PERMISSION);
  }

}
