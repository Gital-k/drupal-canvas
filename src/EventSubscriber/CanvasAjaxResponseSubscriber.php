<?php

namespace Drupal\canvas\EventSubscriber;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\UpdateBuildIdCommand;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class CanvasAjaxResponseSubscriber implements EventSubscriberInterface {

  /**
   * Adds update_build_id command if needed.
   */
  public function onKernelResponse(ResponseEvent $event) {
    $response = $event->getResponse();
    if ($response instanceof AjaxResponse) {
      $commands = $response->getCommands();
      $hasInsertOrRemove = FALSE;
      $hasUpdateBuildId = FALSE;
      foreach ($commands as $command) {
        if (isset($command['command']) && in_array($command['command'], ['insert', 'remove'])) {
          $hasInsertOrRemove = TRUE;
        }
        if (isset($command['command']) && $command['command'] === 'update_build_id') {
          $hasUpdateBuildId = TRUE;
        }
      }
      if ($hasInsertOrRemove && !$hasUpdateBuildId) {
        // Try to get build IDs from the request or generate them.
        $request = $event->getRequest();
        $old_build_id = $request->request->get('form_build_id') ?: 'gen-' . Crypt::randomBytesBase64();;
        $new_build_id = 'gen-' . Crypt::randomBytesBase64();
        $response->addCommand(new UpdateBuildIdCommand($old_build_id, $new_build_id));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Priority 0: after FormAjaxResponseBuilder (default 0), before others.
    return [
      KernelEvents::RESPONSE => ['onKernelResponse', 0],
    ];
  }
}

