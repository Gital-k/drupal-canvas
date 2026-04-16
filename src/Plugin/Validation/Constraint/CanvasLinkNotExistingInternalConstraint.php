<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\link\Plugin\Validation\Constraint\LinkNotExistingInternalConstraint;

/**
 * Canvas shim for core's LinkNotExistingInternal constraint.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasSkipEmptyLinkItemTrait
 */
final class CanvasLinkNotExistingInternalConstraint extends LinkNotExistingInternalConstraint {

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return CanvasLinkNotExistingInternalConstraintValidator::class;
  }

}
