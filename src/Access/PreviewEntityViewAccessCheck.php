<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access checker to check if a ContentTemplate preview entity is viewable.
 *
 * @see \Drupal\canvas\Routing\ContentTemplatePreviewEntityConverter
 * @internal
 */
final class PreviewEntityViewAccessCheck implements AccessInterface {

  public function access(ContentEntityInterface $preview_entity, AccountInterface $account): AccessResultInterface {
    return $preview_entity->access('view', $account, TRUE);
  }

}
