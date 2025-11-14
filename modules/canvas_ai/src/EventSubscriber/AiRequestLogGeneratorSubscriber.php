<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\EventSubscriber;

use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiAgentFinishedExecution;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiAgentStartedExecution;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiProviderRequest;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiProviderResponse;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\TextGenerated;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\ToolSelected;
use Drupal\canvas_ai\Event\CanvasAiLogEvents;
use Drupal\canvas_ai\Event\PrepareAiLogEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to PrepareAiLogEvent to build the AI request log array.
 */
class AiRequestLogGeneratorSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The site UUID.
   *
   * @var string
   */
  protected $siteUuid;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new AiRequestLogGeneratorSubscriber.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(AccountProxyInterface $current_user, ConfigFactoryInterface $config_factory) {
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
    $this->siteUuid = (string) $this->configFactory->get('system.site')->get('uuid');
  }

  /**
   * Builds the log array and sets it on the event.
   *
   * @param \Drupal\canvas_ai\Event\PrepareAiLogEvent $event
   *   The event object.
   */
  public function prepareAiRequestLog(PrepareAiLogEvent $event): void {
    $log_data = [];
    foreach ($event->getPromptStatusUpdate()->getItems() as $log) {
      $agent_id = $log->getAgentId();
      if ($log instanceof AiAgentStartedExecution) {
        $log_data[$agent_id]['name'] = $log->getAgentName();
        $log_data[$agent_id]['executions_started_timestamp'] = $log->getTime();
      }
      elseif ($log instanceof TextGenerated) {
        $log_data[$agent_id]['text_response'] = $log->getGeneratedText();
        $log_data[$agent_id]['calling_agent_name'] = $log->getAgentName();
      }
      elseif ($log instanceof ToolSelected) {
        $log_data[$agent_id]['tool_name'][] = $log->getToolName();
        $log_data[$agent_id]['tool_input'][$log->getToolName()] = $log->getToolInput();
      }
      elseif ($log instanceof AiAgentFinishedExecution) {
        $log_data[$agent_id]['executions_finished_timestamp'] = $log->getTime();
        // Calculate execution time if start time is available.
        if (isset($log_data[$agent_id]['executions_started_timestamp'])) {
          $log_data[$agent_id]['execution_time'] = $log_data[$agent_id]['executions_finished_timestamp'] - $log_data[$agent_id]['executions_started_timestamp'];
        }
      }
      elseif ($log instanceof AiProviderRequest) {
        $log_data[$agent_id]['provider_name'] = $log->getProviderName();
        $log_data[$agent_id]['model_name'] = $log->getModelName();
      }
      elseif ($log instanceof AiProviderResponse) {
        $log_data[$agent_id]['input_tokens'] = $log->getResponseData()['tokenUsage']['input'];
        $log_data[$agent_id]['output_tokens'] = $log->getResponseData()['tokenUsage']['output'];
        $log_data['total_tokens'] = $log_data[$agent_id]['input_tokens'] + $log_data[$agent_id]['output_tokens'];
      }
    }
    $log_data['user_id'] = hash('sha256', (string) $this->currentUser->id());
    $log_data['site_id'] = hash('sha256', $this->siteUuid);
    // Unique ID for this log entry.
    $log_data['id'] = hash('sha256', $this->siteUuid . $this->currentUser->id());
    $event->setLogs($log_data);
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
