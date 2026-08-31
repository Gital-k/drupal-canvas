<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use JsonSchema\Tool\Validator\RelativeReferenceValidator;
use JsonSchema\Tool\Validator\UriValidator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates that a value is a URI, optionally allowing relative references.
 *
 * `temporary://` is always accepted: it occurs while a file is being uploaded
 * and is never a permanently stored value.
 */
final class UriConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof UriConstraint) {
      throw new UnexpectedTypeException($constraint, __NAMESPACE__ . '\UriConstraint');
    }

    if ($value === NULL) {
      return;
    }

    // Never complain about `temporary://`: this is used while uploading a
    // file, and is never a permanently stored value.
    // @see \Drupal\Core\StreamWrapper\TemporaryStream
    if (str_starts_with($value, 'temporary://')) {
      return;
    }

    $is_valid = match ($constraint->allowReferences) {
      TRUE => UriValidator::isValid($value) || RelativeReferenceValidator::isValid($value),
      FALSE => UriValidator::isValid($value),
    };
    if (!$is_valid) {
      $message = $constraint->allowReferences
        ? $constraint->messageInvalidUriReference
        : $constraint->messageInvalidUri;
      $this->context->buildViolation($message)
        ->setParameter('@value', $value)
        ->setInvalidValue($value)
        ->addViolation();
    }
  }

}
