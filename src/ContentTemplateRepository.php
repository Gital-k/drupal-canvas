<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides information about entity types eligible for Canvas templates.
 */
final readonly class ContentTemplateRepository {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns whether an entity type supports content templates.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type to check.
   *
   * @return bool
   *   TRUE if the entity type supports content templates.
   *
   * @todo Handle content entity types powered by base fields only (no
   *   configurable fields via Field UI). Follow-up issue needed.
   */
  public static function isSupportedEntityType(EntityTypeInterface $entity_type): bool {
    return $entity_type instanceof ContentEntityTypeInterface
      && !$entity_type->entityClassImplements(ComponentTreeEntityInterface::class)
      && (bool) $entity_type->get('field_ui_base_route');
  }

  /**
   * Returns entity type IDs that support content templates.
   *
   * @return string[]
   *   An array of entity type IDs.
   */
  public function getSupportedEntityTypeIds(): array {
    $definitions = array_filter(
      $this->entityTypeManager->getDefinitions(),
      self::isSupportedEntityType(...),
    );
    return \array_keys($definitions);
  }

  /**
   * Returns the label for the collection of templateable bundles.
   *
   * For entity types with a bundle entity type this is that bundle entity
   * type's collection label (for example: "Content types" for `node`). For
   * entity types without one it is the entity type's own collection label (for
   * example: "Users" for `user`).
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return string
   *   The collection label.
   */
  public function getBundleCollectionLabel(string $entity_type_id): string {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    \assert($entity_type instanceof EntityTypeInterface);
    $bundle_entity_type_id = $entity_type->getBundleEntityType();
    if ($bundle_entity_type_id === NULL) {
      return (string) $entity_type->getCollectionLabel();
    }
    $bundle_entity_type = $this->entityTypeManager->getDefinition($bundle_entity_type_id);
    \assert($bundle_entity_type instanceof EntityTypeInterface);
    return (string) $bundle_entity_type->getCollectionLabel();
  }

}
