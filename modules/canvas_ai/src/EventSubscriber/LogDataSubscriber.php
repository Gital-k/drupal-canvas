<?php

namespace Drupal\canvas_ai\EventSubscriber;

use Drupal\canvas_ai\Event\LogAiPromptEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Subscribes to LogDataEvent and logs the data.
 */
class LogDataSubscriber implements EventSubscriberInterface {
  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs the LogDataSubscriber.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('canvas_ai');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      LogAiPromptEvent::class => 'onLogData',
    ];
  }

  /**
   * Logs the event data to Drupal logs.
   *
   * @param \Drupal\canvas_ai\Event\LogAiPromptEvent $event
   *   The event object.
   */
  public function onLogData(LogAiPromptEvent $event) {
    $log_data = $event->getLogData();
    $this->logger->info('LogDataEvent: <pre>@data</pre>', ['@data' => print_r($log_data, TRUE)]);
  }

}
