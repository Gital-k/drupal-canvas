<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Creates an empty canvas_page for field widget host-entity context.
 */
trait EmptyCanvasPageHostEntityTrait {

  /**
   * Creates an empty content entity to host field widgets when editing.
   *
   * Some field widgets require a fieldable parent entity context, even when
   * editing config entities such as Patterns or PageRegions.
   *
   * @see \Drupal\Core\Field\FieldItemListInterface::getEntity()
   * @see \Drupal\canvas\PropSource\StaticPropSource::formTemporaryRemoveThisExclamationExclamationExclamation()
   */
  public static function createEmptyHostEntity(): FieldableEntityInterface {
    $entity = \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->create();
    \assert($entity instanceof FieldableEntityInterface);
    return $entity;
  }

}
