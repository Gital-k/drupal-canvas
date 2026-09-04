<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Allows config entities of this type to be organized in Folders.
 *
 * Config entities are listed in the Folder's `items` property. Content
 * entities cannot be: they implement FolderTargetInterface directly and keep
 * a reference to their Folder on the entity itself.
 *
 * @see \Drupal\canvas\Entity\Folder
 * @see \Drupal\canvas\Entity\FolderTargetInterface
 *
 * @internal
 */
interface FolderItemInterface extends ConfigEntityInterface, FolderTargetInterface {}
