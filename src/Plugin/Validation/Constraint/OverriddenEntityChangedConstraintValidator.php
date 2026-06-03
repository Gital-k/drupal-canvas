<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraintValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Validates the EntityChanged constraint.
 */
final class OverriddenEntityChangedConstraintValidator extends EntityChangedConstraintValidator implements ContainerInjectionInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static($container->get(AutoSaveManager::class));
  }

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    if ($entity instanceof Page) {
      $conflict = $this->autoSaveManager->getUnresolvedConflictForEntity($entity);
      if (\is_null($conflict)) {
        return;
      }
    }
    parent::validate($entity, $constraint);
  }

}
