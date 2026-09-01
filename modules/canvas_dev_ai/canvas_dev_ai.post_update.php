<?php

/**
 * @file
 * Post-update functions for the Drupal Canvas Dev AI module.
 */

declare(strict_types=1);

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Reimport the dev page builder agent as its system prompt has changed.
 *
 * The prompt now asks the agent to end the text-only turns it stops on purpose
 * with a marker. Without it the controller cannot tell those apart from a build
 * that stalled, so it would nudge every turn ending for another section.
 *
 * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::endedOnPurpose()
 * @see https://git.drupalcode.org/project/canvas/-/work_items/3592010
 */
function canvas_dev_ai_post_update_0001_reimport_dev_page_builder_agent(): void {
  $name = 'ai_agents.ai_agent.canvas_dev_page_builder_agent';
  $module_path = \Drupal::service(ModuleExtensionList::class)->getPath('canvas_dev_ai');
  $data = (new FileStorage($module_path . '/config/install'))->read($name);
  $config = \Drupal::configFactory()->getEditable($name);
  if ($data && !$config->isNew()) {
    // Only the system prompt changed. Set just that key so the per-site uuid,
    // _core hash, and any other fields stay intact.
    $config->set('system_prompt', $data['system_prompt'])->save(TRUE);

    $message = 'The Canvas dev page builder agent system prompt has been updated. If you had customized it directly, those changes have been overwritten. The recommended way to extend or alter agent behavior is through the Context Control Center or custom event subscribers.';
    \Drupal::logger('canvas_dev_ai')->warning($message);
  }
}
