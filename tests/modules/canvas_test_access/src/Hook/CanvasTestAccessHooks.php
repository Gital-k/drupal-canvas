<?php

declare(strict_types=1);

namespace Drupal\canvas_test_access\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Hook implementations for canvas_test_access.
 */
class CanvasTestAccessHooks {

  /**
   * Implements hook_ENTITY_TYPE_access().
   *
   * Denies view access to canvas_page entity with id 1.
   */
  #[Hook('canvas_page_access')]
  public function canvasPageAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    $cacheContexts = \Drupal::keyValue('canvas_test_access')->get('cache_contexts', []);
    $cacheTags = \Drupal::keyValue('canvas_test_access')->get('cache_tags', []);
    if ((int) $entity->id() === 1 && $operation === 'view') {
      return AccessResult::forbidden();
    }
    if ((int) $entity->id() === 1 && $operation === 'update' && !empty($cacheTags) && !empty($cacheContexts)) {
      return AccessResult::allowed()->addCacheContexts($cacheContexts)->addCacheTags($cacheTags);
    }
    return AccessResult::neutral();
  }

}
