<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\EventSubscriber;

use Drupal\canvas_ai\Event\CanvasAiLogEvents;
use Drupal\canvas_ai\Event\PrepareAiLogEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to PrepareAiLogEvent to build the AI request log array.
 */
class AiRequestLogGeneratorSubscriber implements EventSubscriberInterface {

  /**
   * Builds the log array and sets it on the event.
   *
   * @param \Drupal\canvas_ai\Event\PrepareAiLogEvent $event
   *   The event object.
   */
  public function prepareAiRequestLog(PrepareAiLogEvent $event): void {
    // @todo Add more detailed log data as needed.
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CanvasAiLogEvents::PREPARE_LOGS => 'prepareAiRequestLog',
    ];
  }

}
