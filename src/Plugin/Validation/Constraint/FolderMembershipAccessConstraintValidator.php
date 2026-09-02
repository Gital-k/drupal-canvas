<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\Folder;
use Drupal\canvas\FolderPermissions;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates the FolderMembershipAccess constraint.
 *
 * @internal
 */
final class FolderMembershipAccessConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AccountInterface::class),
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof FolderMembershipAccessConstraint) {
      throw new UnexpectedTypeException($constraint, FolderMembershipAccessConstraint::class);
    }
    if (!$value instanceof EntityReferenceFieldItemListInterface) {
      throw new UnexpectedValueException($value, EntityReferenceFieldItemListInterface::class);
    }
    $entity = $value->getEntity();
    $entity_id = $entity->id();
    // Creating an entity directly in a folder is not a move: whoever may
    // create such entities may file the new one anywhere, and it is governed
    // by that folder's permissions from then on.
    if ($entity->isNew() || $entity_id === NULL) {
      return;
    }
    // TRICKY: validation runs before saving, when the entity does not carry
    // its original yet, so compare against what is stored instead.
    $stored = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity_id);
    $stored_folder_id = $stored instanceof FieldableEntityInterface ? Folder::membershipOf($stored) : NULL;
    $new_folder_id = Folder::membershipOf($entity);
    if ($new_folder_id === $stored_folder_id) {
      return;
    }

    // Moving the entity must not let it escape — or enter — a folder whose
    // editors the current user is not one of: they must still be able to edit
    // the entity where it ends up.
    $entity_type_id = $entity->getEntityTypeId();
    $permissions = [FolderPermissions::siteWidePermission('edit', $entity_type_id)];
    if ($new_folder_id !== NULL) {
      $permissions[] = FolderPermissions::permission('edit', $entity_type_id, $new_folder_id);
    }
    foreach ($permissions as $permission) {
      if ($this->currentUser->hasPermission($permission)) {
        return;
      }
    }

    $arguments = ['@entity' => (string) $entity->getEntityType()->getSingularLabel()];
    if ($new_folder_id === NULL) {
      $this->context->addViolation($constraint->messageOutOfFolder, $arguments);
      return;
    }
    $folder = Folder::load($new_folder_id);
    $arguments['%folder'] = $folder === NULL ? $new_folder_id : (string) $folder->label();
    $this->context->addViolation($constraint->messageIntoFolder, $arguments);
  }

}
