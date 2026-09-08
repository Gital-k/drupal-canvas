<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that a referenced Folder organizes the referencing entity's type.
 *
 * A Page must not be filed into a folder of components, and vice versa: every
 * Folder organizes entities of exactly one entity type.
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'FolderTargetEntityType',
  label: new TranslatableMarkup('Folder organizes this entity type', [], ['context' => 'Validation']),
  type: ['string']
)]
final class FolderTargetEntityTypeConstraint extends Constraint {

  public string $message = 'The folder %folder does not organize %entity_type_id entities.';

}
