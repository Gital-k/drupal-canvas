<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas_ai\AgentStatus\AiPromptStatusUpdate;
use Drupal\canvas_ai\Event\CanvasAiLogEvents;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\canvas_ai\Event\PrepareAiLogEvent;
use Drupal\canvas_ai\Event\LogAiPromptEvent;

/**
 * Tests the event subscribers.
 */
class CanvasAiEventSubscriberTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  /**
   * Tests event subscribers.
   */
  public function testEventSubscribers(): void {
    $dispatcher = $this->container->get('event_dispatcher');
    $mock = $this->createMock(LoggerChannelInterface::class);
    $expectedAgentLog = [
      'name' => 'Test Agent',
      'executions_started_timestamp' => 100.1,
      'text_response' => '',
      'calling_agent_name' => 'Test Agent',
      'tool_name' => ['Tool1'],
      'tool_input' => ['Tool1' => 'input1'],
      'executions_finished_timestamp' => 200.1,
      'execution_time' => 100,
      'provider_name' => 'ProviderX',
      'model_name' => 'ModelY',
      'input_tokens' => 10,
      'output_tokens' => 20,
    ];
    $expectedTotalTokens = 30;

    $mock->method('info')
      ->willReturnCallback(function (string $message) use ($expectedAgentLog, $expectedTotalTokens) {
        $logs = json_decode($message, TRUE);
        $this->assertArrayHasKey('test_agent', $logs);
        $agent = $logs['test_agent'];
        foreach ($expectedAgentLog as $key => $value) {
          $this->assertSame($value, $agent[$key], "Failed asserting that agent['$key'] matches expected value.");
        }
        $this->assertSame($expectedTotalTokens, $logs['total_tokens']);
        $this->assertArrayHasKey('user_id', $logs);
        $this->assertArrayHasKey('site_id', $logs);
        $this->assertArrayHasKey('id', $logs);
      });
    $this->container->get('canvas_ai.logs.log_data_subscriber')->setLogger($mock);
    $agent_id = 'test_agent';
    $runner_id = 'runner_1';
    $agent_name = 'Test Agent';
    $items = [
      [
        'type' => 'agent_started',
        'agent_id' => $agent_id,
        'agent_name' => $agent_name,
        'agent_runner_id' => $runner_id,
        'time' => 100.1,
      ],
      [
        'type' => 'text_generated',
        'agent_id' => $agent_id,
        'generated_text' => 'Generated text',
        'agent_name' => $agent_name,
        'agent_runner_id' => $runner_id,
        'time' => 101.12,
        'loop_count' => 0,
      ],
      [
        'type' => 'tool_selected',
        'agent_id' => $agent_id,
        'agent_name' => 'tool_selected',
        'tool_name' => 'Tool1',
        'tool_input' => 'input1',
        'agent_runner_id' => $runner_id,
        'time' => 101.1223,
      ],
      [
        'type' => 'agent_finished',
        'agent_id' => $agent_id,
        'agent_name' => $agent_name,
        'agent_runner_id' => $runner_id,
        'time' => 200.1,
      ],
      [
        'type' => 'ai_provider_request',
        'agent_id' => $agent_id,
        'agent_name' => $agent_name,
        'provider_name' => 'ProviderX',
        'model_name' => 'ModelY',
        'agent_runner_id' => $runner_id,
        'loop_count' => 1,
        'time' => 200.125,
      ],
      [
        'type' => 'ai_provider_response',
        'agent_id' => $agent_id,
        'agent_name' => $agent_name,
        'time' => 200.125,
        'loop_count' => 1,
        'response_data' => [
          'tokenUsage' => [
            'input' => 10,
            'output' => 20,
          ],
        ],
        'agent_runner_id' => $runner_id,
      ],
    ];
    $ai_prompt_status_update = AiPromptStatusUpdate::fromArray(['items' => $items]);
    $event = new PrepareAiLogEvent($ai_prompt_status_update);
    $dispatcher->dispatch($event, CanvasAiLogEvents::PREPARE_LOGS);

    $log_array = $event->getLogs();
    $log_data_event = new LogAiPromptEvent($log_array);
    $dispatcher->dispatch($log_data_event, CanvasAiLogEvents::LOG);
  }

}
