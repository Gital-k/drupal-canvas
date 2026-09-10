<?php

declare(strict_types=1);

namespace Drupal\canvas\Utility;

use Drupal\Core\Field\FieldDefinitionInterface;

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
   * @var list<string>
   */
  private const SUPPORTED_MULTI_VALUED_FIELD_TYPES = [
    'list_string',
    'list_integer',
    'list_float',
  ];

  /**
   * Whether a field supports delta-less multi-valued entity-field mapping.
   */
  public static function supportsDeltaLessMultiValuedMapping(FieldDefinitionInterface $field_definition): bool {
    return \in_array($field_definition->getType(), self::SUPPORTED_MULTI_VALUED_FIELD_TYPES, TRUE);
  }

}
