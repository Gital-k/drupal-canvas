<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\EventSubscriber;

use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiAgentChatHistory;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiAgentFinishedExecution;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiAgentStartedExecution;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiProviderRequest;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiProviderResponse;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\TextGenerated;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\ToolSelected;
use Drupal\canvas_ai\Event\CanvasAiLogEvents;
use Drupal\canvas_ai\Event\PrepareAiLogEvent;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to PrepareAiLogEvent to build the AI request log array.
 */
class AiRequestLogGeneratorSubscriber implements EventSubscriberInterface {

  /**
   * Constructs an AiRequestLogGeneratorSubscriber object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected readonly AccountProxyInterface $currentUser,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the log array and sets it on the event.
   *
   * Business logic unchanged; only refactored for readability and duplication removal.
   */
  public function prepareAiRequestLog(PrepareAiLogEvent $event): void {
    $log_data = [];
    $agent_status_update = $event->getPromptStatusUpdate();

    foreach ($agent_status_update->getItems() as $log) {
      $agent_id = $log->getAgentId();
      $loop_count = method_exists($log, 'getLoopNumber') ? $log->getLoopNumber() : NULL;

      if ($log instanceof AiAgentStartedExecution) {
        if ($log->getCallingAgentId() === NULL) {
          $log_data['agent'] = [
            'name' => $log->getAgentName(),
            'id' => $agent_id,
            'start_time' => $log->getTime(),
          ];
        }
        continue;
      }

      $primary_agent_id = $log_data['agent']['id'] ?? NULL;
      if ($log instanceof TextGenerated) {
        assert(is_int($loop_count));
        if ($primary_agent_id === $agent_id) {
          $log_data['agent']['executions'][$loop_count]['text_response'] = $log->getGeneratedText();
        }
        else {
          assert($log->getCallingAgentId() !== NULL);
          $log_data['agent']['sub_agent'][$agent_id][$loop_count]['text_response'] = $log->getGeneratedText();
        }
        continue;
      }

      if ($log instanceof ToolSelected) {
        $log_data['agent']['tools_used'][] = [
          'agent_id' => $agent_id,
          'tool_id' => $log->getToolName(),
          'input' => $log->getToolInput(),
          'tool_feedback_message' => $log->getToolFeedbackMessage(),
          'time' => $log->getTime(),
        ];
        continue;
      }

      if ($log instanceof AiAgentFinishedExecution) {
        $log_data['agent']['end_time'] = $log->getTime();
        if (isset($log_data['agent']['start_time'])) {
          $log_data['agent']['total_time'] = $log_data['agent']['end_time'] - $log_data['agent']['start_time'];
        }
        continue;
      }

      if ($log instanceof AiProviderRequest) {
        assert(is_int($loop_count));
        if ($primary_agent_id === $agent_id) {
          $this->writeProviderRequest($log_data, $loop_count, $log);
        }
        else {
          assert($log->getCallingAgentId() !== NULL);
          $this->registerSubAgent($log_data, $agent_id);
          $this->writeSubAgentProviderRequest($log_data, $agent_id, $loop_count, $log);
        }
        continue;
      }

      if ($log instanceof AiProviderResponse) {
        assert(is_int($loop_count));
        $token_usage = $log->getResponseData()['tokenUsage'] ?? [];
        if ($primary_agent_id === $agent_id) {
          $log_data['agent']['executions'][$loop_count]['provider_response_time'] = $log->getTime();
          $this->applyTokenUsage($log_data['agent']['executions'][$loop_count], $token_usage);
        }
        else {
          assert($log->getCallingAgentId() !== NULL);
          $log_data['agent']['sub_agent'][$agent_id][$loop_count]['provider_response_time'] = $log->getTime();
          $this->applyTokenUsage($log_data['agent']['sub_agent'][$agent_id][$loop_count], $token_usage);
        }
      }

    }

    $uid = (string) $this->currentUser->id();
    $site_uuid = (string) $this->configFactory->get('system.site')->get('uuid');
    $log_data['user_id'] = Crypt::hashBase64($uid);
    $log_data['site_id'] = Crypt::hashBase64($site_uuid);
    $log_data['status'] = $agent_status_update->getStatus();
    $log_data['message'] = $agent_status_update->getMessage();
    // Each site would have same user IDs, so combine with site UUID
    // for identifying unique user requests across sites.
    $log_data['id'] = Crypt::hashBase64("{$site_uuid}_$uid");

    $event->setLogs($log_data);
  }

  /**
   * Registers a sub-agent in the log data if not already present.
   *
   * @param array $log_data
   *   The log data array.
   * @param string $agent_id
   *   The sub-agent ID to register.
   */
  private function registerSubAgent(array &$log_data, string $agent_id): void {
    if (!in_array($agent_id, $log_data['agent']['sub_agents'] ?? [], TRUE)) {
      $log_data['agent']['sub_agents'][] = $agent_id;
    }
  }

  /**
   * Writes provider request details into the log data for the primary agent.
   *
   * @param array $log_data
   *   The log data array.
   * @param int $loop_count
   *   The loop count for the execution.
   * @param \Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiProviderRequest $log
   *   The provider request log item.
   */
  private function writeProviderRequest(array &$log_data, int $loop_count, AiProviderRequest $log): void {
    $segment = &$log_data['agent']['executions'][$loop_count];
    $segment['provider_name'] = $log->getProviderName();
    $segment['model_name'] = $log->getModelName();
    $segment['provider_request_time'] = $log->getTime();
    $messages = $log->getRequestData()['messages'] ?? [];
    $message = reset($messages);
    if (!empty($message)) {
      assert($message['role'] === 'user');
      $log_data['user_prompt'] = $message['text'];
    }
  }

  /**
   * Writes provider request details into the log data for a sub-agent.
   *
   * @param array $log_data
   *   The log data array.
   * @param string $agent_id
   *   The sub-agent ID.
   * @param int $loop_count
   *   The loop count for the execution.
   * @param \Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiProviderRequest $log
   *   The provider request log item.
   */
  private function writeSubAgentProviderRequest(array &$log_data, string $agent_id, int $loop_count, AiProviderRequest $log): void {
    $log_data['agent']['sub_agent'][$agent_id][$loop_count] = [
      'name' => $log->getAgentName(),
      'agent_id' => $log->getAgentId(),
      'provider_name' => $log->getProviderName(),
      'model_name' => $log->getModelName(),
      'request_time' => $log->getTime(),
    ];
  }

  /**
   * Applies token usage data to the target log segment.
   *
   * @param array $target
   *   The target log segment to update.
   * @param array $token_usage
   *   The token usage data.
   */
  private function applyTokenUsage(array &$target, array $token_usage): void {
    foreach (['input', 'output', 'total'] as $key) {
      if (array_key_exists($key, $token_usage)) {
        $target['token_usage'][$key] = (int) $token_usage[$key];
      }
    }
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
