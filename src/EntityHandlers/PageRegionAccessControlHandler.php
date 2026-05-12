<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Forbids the `delete` operation on PageRegion entities.
 *
 * Page regions are managed by theme settings (created/enabled/disabled by
 * \Drupal\canvas\Hook\PageRegionHooks) and removed by Drupal's config
 * dependency system when their theme is uninstalled — both paths bypass
 * entity access. The only caller that consults this access result is the
 * HTTP API DELETE route, which therefore responds with 403.
 */
final class PageRegionAccessControlHandler extends CanvasConfigEntityAccessControlHandler {

  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'delete') {
      return AccessResult::forbidden('Page regions cannot be deleted; they are managed by theme settings and removed when their theme is uninstalled.');
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
