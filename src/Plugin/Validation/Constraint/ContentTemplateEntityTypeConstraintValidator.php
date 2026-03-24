<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\canvas\ContentTemplateRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates the ContentTemplateEntityType constraint.
 */
final class ContentTemplateEntityTypeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ContentTemplateRepository $contentTemplateRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ContentTemplateRepository::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ContentTemplateEntityTypeConstraint) {
      throw new UnexpectedTypeException($constraint, ContentTemplateEntityTypeConstraint::class);
    }

    if ($value === NULL) {
      return;
    }

    if (!\is_string($value)) {
      throw new UnexpectedValueException($value, 'string');
    }

    if (!\in_array($value, $this->contentTemplateRepository->getSupportedEntityTypeIds(), TRUE)) {
      $this->context->buildViolation($constraint->message)
        ->setInvalidValue($value)
        ->addViolation();
    }
  }

}
