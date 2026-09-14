<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the scheme of an absolute URI against a list of allowed schemes.
 *
 * Relative URIs have no scheme and are accepted. `temporary://` is always
 * accepted: it occurs while a file is being uploaded and is never a
 * permanently stored value.
 */
final class UriSchemeConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof UriSchemeConstraint) {
      throw new UnexpectedTypeException($constraint, __NAMESPACE__ . '\UriSchemeConstraint');
    }

    if ($value === NULL) {
      return;
    }

    // If an absolute URL was given, also validate the scheme.
    $scheme = parse_url($value, PHP_URL_SCHEME);
    if (!\is_null($scheme) && !\in_array($scheme, $constraint->allowedSchemes, TRUE)) {
      // Never complain about `temporary://`: this is used while uploading a
      // file, and is never a permanently stored value.
      // @see \Drupal\Core\StreamWrapper\TemporaryStream
      if ($scheme === 'temporary') {
        return;
      }
      \assert(\is_string($scheme));
      $this->context->buildViolation($constraint->messageInvalidUriScheme)
        ->setParameter('@scheme', $scheme)
        ->setParameter('@allowed-schemes', implode(', ', $constraint->allowedSchemes))
        ->setInvalidValue($value)
        ->addViolation();
    }
  }

}
