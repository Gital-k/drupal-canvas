<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\Plugin\Action\EntityActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Disables a Component config entity.
 */
#[Action(
  id: 'canvas_component_disable',
  label: new TranslatableMarkup('Disable component'),
  type: 'component',
)]
final class DisableComponent extends EntityActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    $entity->disable()->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): mixed {
    $result = $object->access('disable', $account, TRUE);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
