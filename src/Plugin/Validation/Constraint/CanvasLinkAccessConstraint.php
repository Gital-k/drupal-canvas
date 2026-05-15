<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\link\Plugin\Validation\Constraint\LinkAccessConstraint;

/**
 * Canvas shim for core's LinkAccess constraint.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasSkipEmptyLinkItemTrait
 */
final class CanvasLinkAccessConstraint extends LinkAccessConstraint {

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return CanvasLinkAccessConstraintValidator::class;
  }

}
