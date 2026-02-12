<?php

namespace Drupal\canvas_ai\EventSubscriber;

use Drupal\ai_agents\Event\AgentRequestEvent;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Prepends selected component UUID to user input for the page builder agent.
 */
class PageBuilderAgentPreExecuteSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AgentRequestEvent::EVENT_NAME => 'onAgentRequest',
    ];
  }

  /**
   * Prepends selected component UUID to user input if not already present.
   */
  public function onAgentRequest(AgentRequestEvent $event): void {
    if ($event->getAgentId() !== 'canvas_page_builder_agent') {
      return;
    }

    $agent = $event->getAgent();
    if (!$agent instanceof ConfigAiAgentInterface) {
      return;
    }

    $tokens = $agent->getTokenContexts();
    $uuid = $tokens['active_component_uuid'] ?? '';
    if (empty($uuid) || $uuid === 'None') {
      return;
    }

    $input = $event->getChatInput();
    /** @var \Drupal\ai\OperationType\Chat\ChatMessage $message */
    foreach ($input->getMessages() as $message) {
      if ($message->getRole() !== 'user') {
        continue;
      }
      $text = $message->getText();
      if (str_contains($text, $uuid)) {
        continue;
      }
      $message->setText('Selected Component UUID: ' . $uuid . '. For component additions and edits, use this component and its child components as reference.' . "\n" . $text);
    }
  }

}
