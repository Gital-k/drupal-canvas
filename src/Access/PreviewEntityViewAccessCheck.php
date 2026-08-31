<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks `view` access for the entity a preview route renders.
 *
 * The entity arrives as a route parameter, so access is delegated to that
 * entity rather than gated on a Canvas-specific permission.
 */
final class PreviewEntityViewAccessCheck implements AccessInterface {

  public function access(ContentEntityInterface $preview_entity, AccountInterface $account): AccessResultInterface {
    return $preview_entity->access('view', $account, TRUE);
  }

}
