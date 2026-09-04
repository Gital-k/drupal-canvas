<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that the current user may file a content entity where it is going.
 *
 * Folders of content entities are access boundaries: whoever may edit the
 * entities in a folder may not necessarily edit them anywhere else. So the
 * folder an entity is filed into may only be changed by a user who could still
 * edit the entity in its new location — a site-wide editor anywhere, a folder
 * editor only into another folder they may edit in, and nobody but a site-wide
 * editor out of folders altogether. Creating an entity directly in a folder is
 * not a move and is not restricted.
 *
 * @see \Drupal\canvas\FolderPermissions
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'FolderMembershipAccess',
  label: new TranslatableMarkup('Folder membership may be changed by the current user', [], ['context' => 'Validation']),
  type: ['entity_reference']
)]
final class FolderMembershipAccessConstraint extends Constraint {

  public string $messageIntoFolder = 'You cannot move this @entity into the folder %folder: you would not be allowed to edit it there.';

  public string $messageOutOfFolder = 'You cannot move this @entity out of its folder: that requires the permission to edit any @entity.';

}
