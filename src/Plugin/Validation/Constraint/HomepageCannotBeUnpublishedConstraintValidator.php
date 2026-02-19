<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the HomepageCannotBeUnpublished constraint.
 */
class HomepageCannotBeUnpublishedConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    \assert($constraint instanceof HomepageCannotBeUnpublishedConstraint);

    if (!$entity instanceof EntityPublishedInterface) {
      return;
    }

    // Only check if the entity is being unpublished (currently not published).
    if ($entity->isPublished()) {
      return;
    }

    // New entities cannot be the homepage yet.
    if ($entity->isNew()) {
      return;
    }

    // Load the original entity from storage to check if we're transitioning
    // from published to unpublished.
    // @see \Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraintValidator
    $saved_entity = $this->entityTypeManager
      ->getStorage($entity->getEntityTypeId())
      ->loadUnchanged($entity->id());

    if (!$saved_entity instanceof EntityPublishedInterface || !$saved_entity->isPublished()) {
      // The entity was already unpublished, nothing to check.
      return;
    }

    // Now we know this is a published entity being unpublished.
    $system_config = $this->configFactory->get('system.site');
    $homepage = $system_config->get('page.front');

    try {
      $url = $entity->toUrl('canonical');
      $path_alias = $url->toString();
      $internal_path = '/' . $url->getInternalPath();
      $paths = array_unique([$path_alias, $internal_path]);
    }
    catch (\Exception) {
      // If the entity does not have a canonical URL, we cannot check the path.
      return;
    }

    if (in_array($homepage, $paths, TRUE)) {
      $this->context->addViolation($constraint->message);
    }
  }

}
