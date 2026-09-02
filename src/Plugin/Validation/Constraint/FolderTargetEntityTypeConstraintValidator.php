<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\Folder;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the FolderTargetEntityType constraint.
 *
 * @internal
 */
final class FolderTargetEntityTypeConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof FolderTargetEntityTypeConstraint) {
      throw new UnexpectedTypeException($constraint, FolderTargetEntityTypeConstraint::class);
    }
    if ($value === NULL || $value === '') {
      return;
    }
    $folder = Folder::load((string) $value);
    if ($folder === NULL) {
      // Left to the ValidReference constraint.
      return;
    }
    $validated = $this->context->getObject();
    if (!$validated instanceof TypedDataInterface) {
      return;
    }
    $item = $validated->getParent();
    if (!$item instanceof FieldItemInterface) {
      return;
    }
    $host_entity_type_id = $item->getEntity()->getEntityTypeId();
    if ($folder->get('configEntityTypeId') !== $host_entity_type_id) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%folder', (string) $folder->label())
        ->setParameter('%entity_type_id', $host_entity_type_id)
        ->addViolation();
    }
  }

}
