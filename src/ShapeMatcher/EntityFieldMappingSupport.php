<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\options\Plugin\Field\FieldType\ListFloatItem;
use Drupal\options\Plugin\Field\FieldType\ListIntegerItem;
use Drupal\options\Plugin\Field\FieldType\ListStringItem;

/**
 * Shared policy for delta-less multi-valued entity-field mappings.
 *
 * This only covers the narrow set of multi-valued field types that Canvas may
 * allow through the content-entity-reference picker and validation path when
 * the resulting expression omits a delta.
 *
 * @internal
 */
final class EntityFieldMappingSupport {

  /**
   * Whether a field supports delta-less multi-valued entity-field mapping.
   */
  public static function supportsDeltaLessMultiValuedMapping(FieldDefinitionInterface $field_definition): bool {
    return \in_array($field_definition->getFieldStorageDefinition()->getFieldItemClass(), [
      ListStringItem::class,
      ListIntegerItem::class,
      ListFloatItem::class,
    ], TRUE);
  }

}
