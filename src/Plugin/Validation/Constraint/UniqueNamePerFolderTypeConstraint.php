<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that the name is a unique string per Folder type.
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'UniqueNamePerFolderTypeConstraint',
  label: new TranslatableMarkup('Unique name per Folder config entity type', [], ['context' => 'Validation']),
  type: ['string']
)]
final class UniqueNamePerFolderTypeConstraint extends Constraint {

  /**
   * Entity type ID of the folder type that names must be unique within.
   */
  public string $configEntityTypeId;

  /**
   * ID of the folder being validated, excluded from the uniqueness search.
   */
  public string $id;

  /**
   * Violation message for a name another folder of the same type already uses.
   */
  public string $notUnique = 'Name %value is not unique in Folder type "%configEntityTypeId"';

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return ['id', 'configEntityTypeId'];
  }

}
