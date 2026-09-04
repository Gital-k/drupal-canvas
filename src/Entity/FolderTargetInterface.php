<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\EntityInterface;

/**
 * Allows entities of this type to be organized in Folders.
 *
 * A Folder organizes entities of exactly one entity type. How membership is
 * stored depends on the kind of entity being organized:
 * - Config entities (marked with the FolderItemInterface sub-interface) are
 *   listed in the Folder's `items` property, because config may only depend
 *   on config.
 * - Content entities keep a reference to their Folder on the entity itself
 *   (e.g. Page's `folder` base field), because config must not depend on
 *   content: a Folder listing content UUIDs would churn deployed config every
 *   time an editor filed a page, and would reference content that does not
 *   exist on other environments.
 *
 * @see \Drupal\canvas\Entity\Folder
 * @see \Drupal\canvas\Entity\FolderItemInterface
 *
 * @internal
 */
interface FolderTargetInterface extends EntityInterface {}
