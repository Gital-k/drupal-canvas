<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use JsonSchema\Tool\Validator\RelativeReferenceValidator;
use JsonSchema\Tool\Validator\UriValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UriConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(StreamWrapperManagerInterface::class),
    );
  }

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

    // A URI using a registered stream wrapper is not an RFC 3986 URI: what
    // follows `://` is a file path, not an authority plus a path, and file
    // names are never percent-encoded. So RFC 3986 rejects valid file URIs:
    // any file name containing a space, any non-ASCII first path segment, and
    // every file stored at the root of the stream wrapper. Validate the
    // scheme and require a non-empty target instead.
    // @see \Drupal\Core\StreamWrapper\StreamWrapperInterface
    $scheme = StreamWrapperManager::getScheme($value);
    if (\is_string($scheme) && $this->streamWrapperManager->isValidScheme($scheme)) {
      $target = StreamWrapperManager::getTarget($value);
      if ($target === FALSE || $target === '') {
        $this->context->buildViolation($constraint->messageInvalidUri)
          ->setParameter('@value', $value)
          ->setInvalidValue($value)
          ->addViolation();
      }
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
