<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\EventSubscriber;

use Drupal\canvas_ai\Event\CanvasAiLogEvents;
use Drupal\canvas_ai\Event\LogAiPromptEvent;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to LogDataEvent and logs the data.
 */
class LogDataSubscriber implements EventSubscriberInterface {
  use LoggerAwareTrait;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CanvasAiLogEvents::LOG => 'logData',
    ];
  }

  /**
   * Logs the data from the event.
   *
   * @param \Drupal\canvas_ai\Event\LogAiPromptEvent $event
   *   The event object.
   */
  public function logData(LogAiPromptEvent $event): void {
    $json_logs = json_encode($event->getLogs(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $this->logger?->info($json_logs);
  }

}
