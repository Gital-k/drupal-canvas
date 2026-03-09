<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\Plugin\Action\EntityActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Enables a Component config entity.
 */
#[Action(
  id: 'canvas_component_enable',
  label: new TranslatableMarkup('Enable component'),
  type: 'component',
)]
final class EnableComponent extends EntityActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    $entity->enable()->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): mixed {
    $result = $object->access('enable', $account, TRUE);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
