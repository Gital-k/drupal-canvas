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
   * A Pattern renders standalone and a PageRegion is rendered on every page, so
   * neither has a host entity of its own. But building a component instance
   * form still needs a fieldable "parent" entity for field widgets that require
   * entity context (e.g. those calling FieldItemList::getEntity(), such as
   * image, file, options or rich text widgets). Use an empty, unsaved
   * canvas_page for that purpose.
   *
   * @see \Drupal\Core\Field\FieldItemListInterface::getEntity()
   * @see \Drupal\canvas\PropSource\StaticPropSource::formTemporaryRemoveThisExclamationExclamationExclamation()
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::buildComponentInstanceForm()
   * @see \Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget::buildWidgetForm()
   * @see \Drupal\canvas\Entity\ContentTemplate::createEmptyTargetEntity()
   */
  public static function createEmptyHostEntity(): FieldableEntityInterface {
    $entity = \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->create();
    \assert($entity instanceof FieldableEntityInterface);
    return $entity;
  }

}
