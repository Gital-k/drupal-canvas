<?php

/**
 * @file
 * Contains an Installer.
 *
 * @license GPL v2 http://www.fsf.org/licensing/licenses/gpl.html
 * @author Chris Skene chris at xtfer dot com
 * @copyright Copyright(c) 2015 Christopher Skene
 */

namespace Drupal\canvas_core\Installer;

use Drupal\canvas\Exception\CanvasException;
use Drupal\canvas\ConfigTasks\ConfigTaskInterface;
use Drupal\canvas_core\Util\Features;
use Drupal\config\Config;
use Drupal\ghost\Logger\Logger;

/**
 * Class Installer
 * @package Drupal\canvas_core\Installer
 */
class Installer {

  /**
   * Lazy constructor.
   *
   * @return static
   *   An instance of Installer.
   * @static
   */
  static public function init() {

    return new static();
  }

  /**
   * Perform actions to set up the site for this profile.
   */
  public function runInstall() {

    // Build a list of configuration tasks and run them.
    $config_tasks = $this::getConfigTasks();
    foreach ($config_tasks as $task_name => $task_handler) {

      $log = Logger::init('canvas')
        ->setMessage('Successfully installed %component.')
        ->setVar('%component', $task_name)
        ->useMessageArea();

      // Load a handler for the task.
      try {
        $handler = $this->buildTaskHandler($task_handler);
      }
      catch(CanvasException $e) {
        // We failed, so change the message to a failure.
        $log->setMessage('Failed to load install task: %component. %message')
          ->setVar('%message', $e->getMessage())
          ->setSeverity(WATCHDOG_ERROR)
          ->logMessage();

        continue;
      }

      // Handler loaded successfully... run the task.
      try {
        $handler->doConfig();
      }
      catch(CanvasException $e) {

        // We failed, so change the message to a failure.
        $log->setMessage('Failed to complete install task: %component. %message')
          ->setVar('%message', $e->getMessage())
          ->setSeverity(WATCHDOG_ERROR);
      }

      $log->logMessage();
    }

    cache_clear_all();
    node_access_needs_rebuild(TRUE);
  }

  /**
   * Get config task information.
   *
   * @return array
   *   An array of config tasks.
   */
  public function getConfigTasks() {
    return Config::load()->getConfig('canvas_core', 'config', 'config.json', CONFIG_PARSER_JSON);
  }

  /**
   * Build a ConfigTaskInterface handler.
   *
   * @param string $task_handler
   *   Name of the task handler.
   *
   * @return ConfigTaskInterface
   *   An instance of ConfigTaskInterface
   * @throws \Drupal\canvas\Exception\CanvasException
   */
  protected function buildTaskHandler($task_handler) {

    if (!class_exists($task_handler)) {
      throw new CanvasException(sprintf('Invalid task handler %s', $task_handler));
    }

    /* @var ConfigTaskInterface $task_handler */
    $handler = $task_handler::init();

    return $handler;
  }

}
