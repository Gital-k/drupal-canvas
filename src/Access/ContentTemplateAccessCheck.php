<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks access for viewing/editing content templates.
 *
 * Verifies that the user has access to content templates and validates
 * the entity_type and view_mode parameters.
 *
 * @internal
 */
final class ContentTemplateAccessCheck implements AccessInterface {

  /**
   * Checks access for content template routes.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account being checked.
   * @param string $entity_type
   *   The entity type ID (e.g., 'node').
   * @param string $view_mode
   *   The view mode (e.g., 'full').
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, string $entity_type, string $view_mode): AccessResultInterface {
    //This method still needs refactoring. I just added basic checks to get it working for now.
    // We might need to add the check related to status of the content template.


    // Check if the user has the admin permission for content templates.
    $permission_access = AccessResult::allowedIfHasPermission($account, ContentTemplate::ADMIN_PERMISSION);

    if (!$permission_access->isAllowed()) {
      return $permission_access->setReason('User does not have permission to administer content templates.');
    }

    // Validate that entity_type and view_mode are provided.
    if (empty($entity_type) || empty($view_mode)) {
      return AccessResult::forbidden('Entity type and view mode are required.')->addCacheContexts(['url.path']);
    }

    return $permission_access->addCacheContexts(['url.path']);
  }

}


