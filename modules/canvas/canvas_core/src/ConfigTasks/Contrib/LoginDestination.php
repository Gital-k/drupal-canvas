<?php

/**
 * @file
 * Contains a
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas\ConfigTasks\Contrib;

use Drupal\canvas\ConfigTasks\ConfigTaskBase;
use Drupal\canvas\ConfigTasks\ConfigTaskInterface;
use Drupal\canvas\Exception\CanvasInstallerException;

/**
 * Class LoginDestination
 * @package Drupal\canvas\ConfigTasks\Contrib
 */
class LoginDestination extends ConfigTaskBase implements ConfigTaskInterface {

  /**
   * Activate the configuration.
   */
  public function doConfig() {
    $role = user_role_load_by_name('Site editor');
    $rid = $role->rid;
    $record = array(
      'triggers' => serialize(array('login' => 'login')),
      'roles' => serialize(array($rid => $rid)),
      'page_types' => 0,
      'pages' => '',
      'destination_type' => 0,
      'destination' => 'admin/workbench',
      'weight' => 0,
    );
    $result = drupal_write_record('login_destination', $record);

    if ($result == FALSE) {
      throw new CanvasInstallerException();
    }
  }

  /**
   * Fetch the configuration parameters.
   *
   * @return mixed
   *   Parameters suitable for doConfig() to use.
   */
  public function getConfig() {
    // TODO: Implement getConfig() method.
  }

}
