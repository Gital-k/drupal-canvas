<?php

namespace Drupal\canvas_ai\EventSubscriber;

use Drupal\canvas_ai\Event\PrepareAiLogEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Subscribes to PrepareAiLogEvent to build the AI request log array.
 */
class AiRequestLogGeneratorSubscriber implements EventSubscriberInterface {
  /**
   * The AI agent status storage service.
   *
   * @var object
   */
  private $statusStorage;

  /**
   * Constructs the subscriber.
   *
   * @param object $status_storage
   *   The AI agent status storage service.
   */
  public function __construct($status_storage) {
    $this->statusStorage = $status_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_agents.private_temp_status_storage')
    );
  }

  /**
   * Builds the log array and sets it on the event.
   *
   * @param \Drupal\canvas_ai\Event\PrepareAiLogEvent $event
   *   The event object.
   */
  public function onAiRequestLog(PrepareAiLogEvent $event): void {
    $agent_status = \Drupal::service('ai_agents.private_temp_status_storage')
      ->loadStatusUpdate($event->getPrompt()['request_id']);
    $prompt_status = $agent_status ? $agent_status->toArray() : [];
    $log_data = [
      'user_id' => hash('sha256', (string) $event->getCurrentUser()->id()),
      'site_id' => hash('sha256', (string) $event->getSiteConfig()->get('uuid')),
      'request_id' => $event->getPrompt()['request_id'],
      'status' => $prompt_status,
      'result' => $event->getErrorMessage() ? 'failure' : 'success',
    ];
    if ($event->getErrorMessage()) {
      $log_data['error'] = $event->getErrorMessage();
    }
    $event->setLogArray($log_data);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PrepareAiLogEvent::class => 'onAiRequestLog',
    ];
  }

}
