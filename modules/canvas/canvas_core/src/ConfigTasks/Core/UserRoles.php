<?php

/**
 * @file
 * Contains a UserRoles config task.
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\ConfigTasks\Core;

use Drupal\canvas\ConfigTasks\ConfigTaskInterface;
use Drupal\canvas\ConfigTasks\ConfigTaskBase;
use Drupal\canvas\Exception\CanvasInstallerException;

/**
 * Class UserRoles
 * @package Drupal\canvas\ConfigTasks
 */
class UserRoles extends ConfigTaskBase implements ConfigTaskInterface {

  /**
   * Activate the configuration.
   */
  public function doConfig() {

    // Base roles.

    // Anonymous user.
    user_role_grant_permissions(
      DRUPAL_ANONYMOUS_RID,
      array(
        'access content',
      )
    );

    // Authenticated user.
    user_role_grant_permissions(
      DRUPAL_AUTHENTICATED_RID,
      array(
        'access content',
        $this->permissionName('rich_text'),
      )
    );

    $roles = $this->getConfig();

    // Create the roles.
    $weight = 1;
    foreach ($roles as $role) {
      $role_object = new \stdClass();
      $role_object->name = $role['name'];
      $role_object->weight = $role['weight'];

      // Save the role.
      user_role_save($role_object);

      // Grant permissions.
      if (!empty($role['permissions'])) {
        user_role_grant_permissions($role_object->rid, $role['permissions']);
      }

      $weight = $role['weight'];
    }

    // Create a default role for site administrators, with all available
    // permissions assigned.
    $admin_role = new \stdClass();
    $admin_role->name = 'administrator';
    $admin_role->weight = $weight + 1;
    user_role_save($admin_role);
    user_role_grant_permissions($admin_role->rid, array_keys(module_invoke_all('permission')));
    // Set this as the administrator role.
    variable_set('user_admin_role', $admin_role->rid);

    // Assign user 1 the "administrator" role.
    $id = db_insert('users_roles')
      ->fields(array('uid' => 1, 'rid' => $admin_role->rid))
      ->execute();

    if (empty($id)) {
      throw new CanvasInstallerException('Failed to insert roles.');
    }
  }

  /**
   * Fetch the configuration parameters.
   *
   * @todo Extract the permissions from this section into their own handlers.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig() {
    $weight = 1;
    $base_permissions = array('access navbar');

    // Content editor.
    $roles['Content editor'] = array(
      'name' => 'Content editor',
      'weight' => $weight++,
      'permissions' => $base_permissions,
    );

    // Content approver.
    $roles['Content approver'] = array(
      'name' => 'Content approver',
      'weight' => $weight++,
      'permissions' => $base_permissions,
    );

    $extended_permissions = $base_permissions + array(
      'administer google analytics reports',
      'access google analytics reports',
    );

    // Site builder.
    $roles['Site builder'] = array(
      'name' => 'Site builder',
      'weight' => $weight++,
      'permissions' => $extended_permissions,
    );

    // Site editor.
    $roles['Site editor'] = array(
      'name' => 'Site editor',
      'weight' => $weight,
      'permissions' => $extended_permissions,
    );

    return $roles;
  }

  /**
   * Returns the machine-readable permission name for a provided text format.
   *
   * @param string $format_name
   *   The format name.
   *
   * @return string
   *   The machine-readable permission name.
   */
  public function permissionName($format_name) {
    return 'use text format ' . $format_name;
  }
}
