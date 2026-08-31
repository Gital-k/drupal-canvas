<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates nothing: the constraint it serves only carries metadata.
 *
 * Symfony requires a validator for every constraint, so this exists to satisfy
 * that contract.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\UriTargetFileExtensionsConstraint
 */
final class UriTargetFileExtensionsConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof UriTargetFileExtensionsConstraint) {
      throw new UnexpectedTypeException($constraint, __NAMESPACE__ . '\UriTargetFileExtensionsConstraint');
    }

    // No-op.
  }

}
