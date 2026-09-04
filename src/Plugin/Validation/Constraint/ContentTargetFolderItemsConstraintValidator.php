<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Config\Schema\TypeResolver;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the ContentTargetFolderItemsConstraint constraint.
 *
 * @internal
 */
final class ContentTargetFolderItemsConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(EntityTypeManagerInterface::class));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ContentTargetFolderItemsConstraint) {
      throw new UnexpectedTypeException($constraint, ContentTargetFolderItemsConstraint::class);
    }
    // Sequence-level constraints receive the typed data, not the plain array.
    if ($value instanceof TypedDataInterface) {
      $value = $value->getValue();
    }
    if (!\is_array($value) || $value === []) {
      return;
    }
    // @phpstan-ignore argument.type
    $target_entity_type_id = TypeResolver::resolveDynamicTypeName("[$constraint->configEntityTypeId]", $this->context->getObject());
    $entity_type = $this->entityTypeManager->getDefinition($target_entity_type_id, FALSE);
    if ($entity_type instanceof ContentEntityTypeInterface) {
      $this->context->addViolation($constraint->message, [
        '%entity_type_id' => $target_entity_type_id,
      ]);
    }
  }

}
