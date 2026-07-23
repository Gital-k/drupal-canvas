<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides information about entity types eligible for Canvas templates.
 */
final readonly class ContentTemplateRepository {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private ModuleHandlerInterface $moduleHandler,
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
  public function isSupportedEntityType(EntityTypeInterface $entity_type): bool {
    // Content templates are edited through Field UI's "Manage display" routes,
    // so the feature is only available when Field UI is installed. Note that
    // `field_ui_base_route` is declared in entity annotations (e.g. node) and
    // is present even without the Field UI module, so it must be gated on the
    // module being enabled — otherwise Canvas would advertise templates for
    // entity types whose Field UI routes do not exist. Without Field UI, Canvas
    // still works for its own component-tree entities (e.g. canvas_page).
    return $this->moduleHandler->moduleExists('field_ui')
      && $entity_type instanceof ContentEntityTypeInterface
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
      fn($entity_type): bool => $this->isSupportedEntityType($entity_type),
    );
    return \array_keys($definitions);
  }

}
