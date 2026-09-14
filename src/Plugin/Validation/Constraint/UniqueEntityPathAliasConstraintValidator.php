<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Path\Plugin\Validation\Constraint\UniquePathAliasConstraint;
use Drupal\path_alias\PathAliasInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the `UniqueEntityPathAlias` constraint.
 *
 * The uniqueness rule itself is core's, and is not restated here: this builds
 * the `path_alias` entity that PathItem::postSave() would create and validates
 * that, so the alias is held to exactly the same rule — including the separate
 * message for a clash that differs only in capitalization.
 *
 * @see \Drupal\Core\Path\Plugin\Validation\Constraint\UniquePathAliasConstraintValidator
 */
final class UniqueEntityPathAliasConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof UniqueEntityPathAliasConstraint);
    if (!$value instanceof FieldItemListInterface) {
      return;
    }
    $item = $value->first();
    if ($item === NULL) {
      return;
    }
    // Mirror PathItem::preSave(), which trims before the alias is persisted.
    $alias = \trim((string) $item->get('alias')->getValue());
    if ($alias === '') {
      return;
    }

    // The system path the alias will point at. A page that was never saved has
    // no route yet, so it has no system path to exclude from the lookup — any
    // match is then a clash by definition.
    // @see \Drupal\path\Plugin\Field\FieldType\PathItem::postSave()
    $entity = $value->getEntity();
    $source = $entity->isNew() ? NULL : '/' . $entity->toUrl()->getInternalPath();

    // An explicit alias langcode wins, exactly as it does on save.
    $alias_langcode = $item->get('langcode')->getValue();
    $path_alias = $this->entityTypeManager->getStorage('path_alias')->create([
      'path' => $source,
      'alias' => $alias,
      'langcode' => \is_string($alias_langcode) && $alias_langcode !== ''
        ? $alias_langcode
        : $value->getLangcode(),
    ]);
    \assert($path_alias instanceof PathAliasInterface);

    foreach ($path_alias->validate() as $violation) {
      // Only uniqueness is delegated. The prospective entity is a throwaway
      // stand-in, so its other violations describe it rather than the page —
      // notably `ValidPath`, which route-access-checks the page's own
      // canonical path and reports one for a page that has none yet.
      if (!$violation->getConstraint() instanceof UniquePathAliasConstraint) {
        continue;
      }
      // Report against the alias the client sent, so the violation reaches the
      // `path[0][alias]` form element the Canvas UI renders.
      $this->context->buildViolation((string) $violation->getMessage())
        ->atPath('0.alias')
        ->addViolation();
    }
  }

}
