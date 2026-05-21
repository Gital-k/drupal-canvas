<?php

declare(strict_types=1);

namespace Drupal\canvas_test_field_access\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Hook implementations for canvas_test_field_access.
 */
class CanvasTestFieldAccessHooks {

  /**
   * Implements hook_entity_field_access().
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($field_definition->getTargetEntityTypeId() === Page::ENTITY_TYPE_ID && $field_definition->getName() == 'components' && $operation == 'edit') {
      return AccessResult::forbiddenIf(condition: !$account->hasPermission('edit canvas page components'), reason: 'You do not have permission to edit this field.')->cachePerPermissions();
    }
    if ($field_definition->getTargetEntityTypeId() === 'node' && $field_definition->getName() == 'title' && $operation == 'edit') {
      return AccessResult::forbiddenIf(condition: !$account->hasPermission('edit canvas page components'), reason: 'You do not have permission to edit this field.')->cachePerPermissions();
    }
    return AccessResult::neutral();
  }

}
