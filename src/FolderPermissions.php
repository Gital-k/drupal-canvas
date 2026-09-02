<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\Folder;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\Entity\Role;

/**
 * Provides the permissions that are scoped to one folder of content entities.
 *
 * A folder that organizes content entities (today: pages) is more than a way to
 * group them in the Pages panel: it is also an access boundary. For every such
 * folder, two permissions are generated — one to edit and one to delete
 * whatever is filed into it — so a role can be trusted with a single folder
 * without holding the site-wide `edit canvas_page` or `delete canvas_page`
 * permission.
 *
 * Each generated permission declares its folder as a config dependency, which
 * is what makes the scheme self-maintaining: deleting a folder revokes its
 * permissions from every role, exactly like deleting a node type revokes the
 * node module's per-bundle permissions.
 *
 * @see canvas.permissions.yml
 * @see \Drupal\node\NodePermissions
 * @see \Drupal\user\Entity\Role::onDependencyRemoval()
 * @see \Drupal\canvas\Entity\PageAccessControlHandler
 * @see \Drupal\canvas\Entity\FolderAccessControlHandler
 * @see \Drupal\canvas\Plugin\Validation\Constraint\FolderMembershipAccessConstraint
 */
final class FolderPermissions implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  /**
   * The operations that can be granted per folder.
   *
   * Each one narrows down the site-wide `<operation> <entity type ID>`
   * permission of the organized entity type, e.g. `edit canvas_page`.
   *
   * @see \Drupal\canvas\Entity\Page::EDIT_PERMISSION
   * @see \Drupal\canvas\Entity\Page::DELETE_PERMISSION
   * @see ::siteWidePermission()
   */
  public const array OPERATIONS = ['edit', 'delete'];

  /**
   * The operations whose site-wide permission implies seeing every folder.
   *
   * How the content of a type is organized is not a secret to whoever may
   * create, edit or delete it anywhere: they see every folder, and only
   * administering folders themselves stays behind `administer folders`.
   *
   * @see ::checkFolderVisibility()
   */
  private const array VISIBILITY_OPERATIONS = ['create', 'edit', 'delete'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Permission callback: an edit and a delete permission per folder.
   *
   * Only folders organizing content entities get permissions: for config
   * entities such as components, folders remain a purely organizational tool.
   *
   * @return array<string, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, description: \Drupal\Core\StringTranslation\TranslatableMarkup, dependencies: array{config: list<string>}}>
   *   The permission definitions, keyed by permission machine name.
   */
  public function permissions(): array {
    $permissions = [];
    foreach ($this->entityTypeManager->getStorage(Folder::ENTITY_TYPE_ID)->loadMultiple() as $folder) {
      \assert($folder instanceof Folder);
      if (!$folder->targetsContentEntityType()) {
        continue;
      }
      $folder_id = $folder->id();
      \assert(\is_string($folder_id));
      $entity_type_id = $folder->getConfigEntityTypeId();
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $arguments = [
        '%folder' => (string) $folder->label(),
        '@entity' => (string) $entity_type->getSingularLabel(),
        '@entities' => (string) $entity_type->getPluralLabel(),
      ];
      // Roles holding these depend on the folder, so deleting it revokes them.
      $dependencies = ['config' => [$folder->getConfigDependencyName()]];
      $permissions[self::permission('edit', $entity_type_id, $folder_id)] = [
        'title' => $this->t('Folder %folder: Edit @entities', $arguments),
        'description' => $this->t('Edit every @entity in this folder, without the site-wide permission to edit any @entity.', $arguments),
        'dependencies' => $dependencies,
      ];
      $permissions[self::permission('delete', $entity_type_id, $folder_id)] = [
        'title' => $this->t('Folder %folder: Delete @entities', $arguments),
        'description' => $this->t('Delete every @entity in this folder, without the site-wide permission to delete any @entity.', $arguments),
        'dependencies' => $dependencies,
      ];
    }
    return $permissions;
  }

  /**
   * Builds the machine name of a folder-scoped permission.
   *
   * @param string $operation
   *   One of self::OPERATIONS.
   * @param string $entity_type_id
   *   The ID of the content entity type the folder organizes.
   * @param string $folder_id
   *   The folder ID.
   *
   * @return string
   *   The permission, e.g. `edit canvas_page in folder <folder ID>`.
   */
  public static function permission(string $operation, string $entity_type_id, string $folder_id): string {
    \assert(\in_array($operation, self::OPERATIONS, TRUE));
    return \sprintf('%s %s in folder %s', $operation, $entity_type_id, $folder_id);
  }

  /**
   * Builds the prefix shared by every folder-scoped permission of an operation.
   *
   * A role's permission list is the cheapest way to tell a folder editor from
   * anyone else: the folder's ID is part of the permission name, so no folder
   * has to be loaded and no response ends up depending on the folder list.
   *
   * @param string $operation
   *   One of self::OPERATIONS.
   * @param string $entity_type_id
   *   The ID of the content entity type the folders organize.
   *
   * @return string
   *   The prefix, e.g. `edit canvas_page in folder `.
   */
  public static function permissionPrefix(string $operation, string $entity_type_id): string {
    return self::permission($operation, $entity_type_id, '');
  }

  /**
   * Builds the machine name of the permission a folder-scoped one narrows down.
   *
   * Canvas names the site-wide permissions of the content entity types it
   * provides `<operation> <entity type ID>`; every content entity type that
   * can be organized into folders is expected to follow that convention.
   *
   * @param string $operation
   *   One of self::OPERATIONS.
   * @param string $entity_type_id
   *   The ID of the content entity type.
   *
   * @return string
   *   The permission, e.g. `edit canvas_page`.
   *
   * @see \Drupal\canvas\Entity\Page::EDIT_PERMISSION
   */
  public static function siteWidePermission(string $operation, string $entity_type_id): string {
    return \sprintf('%s %s', $operation, $entity_type_id);
  }

  /**
   * The IDs of the folders an account holds an operation's permission for.
   *
   * The folder's ID is part of the permission name, so the account's roles
   * carry the answer: no folder is loaded, and nothing that asks this question
   * ends up depending on the list of folders. Reading the roles rather than
   * every defined permission also keeps this free of side effects — collecting
   * all permissions runs every module's callbacks.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param string $operation
   *   One of self::OPERATIONS.
   * @param string $entity_type_id
   *   The ID of the content entity type the folders organize.
   *
   * @return list<string>
   *   Folder IDs, empty when the account may not operate in any folder.
   */
  public static function folderIds(AccountInterface $account, string $operation, string $entity_type_id): array {
    $prefix = self::permissionPrefix($operation, $entity_type_id);
    $folder_ids = [];
    // TRICKY: the interface documents an array of role IDs, but a test double
    // that does not stub getRoles() returns NULL — and access checks are
    // exactly what such doubles drive.
    $role_ids = $account->getRoles();
    foreach (\is_array($role_ids) ? $role_ids : [] as $role_id) {
      $role = Role::load($role_id);
      if ($role === NULL) {
        continue;
      }
      foreach ($role->getPermissions() as $permission) {
        if (str_starts_with($permission, $prefix)) {
          $folder_ids[] = substr($permission, \strlen($prefix));
        }
      }
    }
    return \array_values(\array_unique($folder_ids));
  }

  /**
   * Checks an operation on a content entity through the folder it is filed in.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param string $operation
   *   One of self::OPERATIONS.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   A content entity that can be organized into folders.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed when the account holds the permission scoped to the entity's
   *   folder; neutral when it does not, or when the entity is in no folder —
   *   only the entity type's site-wide permissions can grant the operation
   *   then.
   */
  public static function checkFolderAccess(AccountInterface $account, string $operation, FieldableEntityInterface $entity): AccessResultInterface {
    $folder_id = Folder::membershipOf($entity);
    if ($folder_id === NULL) {
      return AccessResult::neutral('The entity is in no folder.')->cachePerPermissions();
    }
    $access = AccessResult::allowedIfHasPermission($account, self::permission($operation, $entity->getEntityTypeId(), $folder_id));
    if ($access->isAllowed()) {
      // Filing the entity into another folder takes this away again. A denial
      // deliberately does not depend on the entity: it would put the entity's
      // cache tag into every response that checks an operation it does not
      // grant, and whatever renders or lists the entity already depends on it.
      $access->addCacheableDependency($entity);
    }
    return $access;
  }

  /**
   * Checks whether an account may see a folder of content entities.
   *
   * The Pages panel groups pages by folder, so a folder is visible to whoever
   * may organize the content it holds: an editor of that content entity type
   * anywhere, or the holder of a permission scoped to this folder. Everyone
   * else is served no folder at all and gets a flat list.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param \Drupal\canvas\Entity\Folder $folder
   *   A folder organizing content entities.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed when the account may see the folder, neutral otherwise.
   */
  public static function checkFolderVisibility(AccountInterface $account, Folder $folder): AccessResultInterface {
    $folder_id = $folder->id();
    \assert(\is_string($folder_id));
    $entity_type_id = $folder->getConfigEntityTypeId();
    $permissions = \array_map(
      fn (string $operation): string => self::siteWidePermission($operation, $entity_type_id),
      self::VISIBILITY_OPERATIONS,
    );
    foreach (self::OPERATIONS as $operation) {
      $permissions[] = self::permission($operation, $entity_type_id, $folder_id);
    }
    // Only the account's permissions decide, and a folder response carries the
    // folder's own cache tag anyway.
    return AccessResult::allowedIfHasPermissions($account, $permissions, 'OR');
  }

}
