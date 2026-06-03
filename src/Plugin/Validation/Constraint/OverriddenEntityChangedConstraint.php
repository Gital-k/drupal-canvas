<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

/**
 * Validation constraint for the entity changed timestamp.
 */
#[Constraint(
  id: 'OverriddenEntityChanged',
  label: new TranslatableMarkup('Entity changed', [], ['context' => 'Validation']),
  type: ['entity']
)]
class OverriddenEntityChangedConstraint extends EntityChangedConstraint {}
