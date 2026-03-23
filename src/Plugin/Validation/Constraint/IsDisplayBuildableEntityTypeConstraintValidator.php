<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @internal
 */
final class IsDisplayBuildableEntityTypeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof IsDisplayBuildableEntityTypeConstraint) {
      throw new UnexpectedTypeException($constraint, IsDisplayBuildableEntityTypeConstraint::class);
    }

    if ($value === NULL) {
      return;
    }
    elseif (!\is_string($value)) {
      throw new UnexpectedValueException($value, 'string');
    }

    $entity_type = $this->entityTypeManager->getDefinition($value, FALSE);
    if ($entity_type === NULL) {
      return;
    }

    if (!$entity_type instanceof ContentEntityTypeInterface) {
      $this->context->buildViolation($constraint->notContentEntityTypeMessage)
        ->setParameter('@entity_type_id', $value)
        ->setInvalidValue($value)
        ->addViolation();
      return;
    }

    if (!$entity_type->get('field_ui_base_route')) {
      $this->context->buildViolation($constraint->noFieldUiBaseRouteMessage)
        ->setParameter('@entity_type_id', $value)
        ->setInvalidValue($value)
        ->addViolation();
    }
  }

}
