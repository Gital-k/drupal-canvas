<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiAgentStartedExecution;
use Drupal\canvas_ai\AgentStatus\AiPromptStatusUpdate;
use Drupal\canvas_ai\Event\CanvasAiLogEvents;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Uuid\Uuid;
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

    $this->container->get('config.factory')
      ->getEditable('system.site')
      ->set('uuid', $this->container->get('uuid')->generate())
      ->save();

    $site_id = $this->container->get('config.factory')->get('system.site')->get('uuid');
    $user_id = $this->container->get('current_user')->id();

    $primary_agent_id = "canvas_ai_orchestrator";
    $primary_agent_name = "Drupal Canvas AI Orchestrator";
    $runner_id = 'runner_1';

    $sub_agents = [
      'canvas_component_agent' => [
        'name' => 'Drupal Canvas Component Agent',
        'id' => 'canvas_component_agent',
      ],
    ];
    $sub_agents_names = array_keys($sub_agents);

    $expected_log_data = [
      'agent' => [
        'name' => $primary_agent_name,
        'id' => $primary_agent_id,
        'start_time' => 1763457698.8586,
        'executions' => [
          1 =>  [
            "text_response" => "I 'll help you create a simple text component. Let me do that for you right away.",
              "provider_name" => "litellm",
              "model_name" => "anthropic.claude-3-7-sonnet",
              "provider_request_time" => 1763457698.8718,
              "provider_response_time" => 1763457703.3533,
              "token_usage" =>  [
                "input" => 3875,
                "output" => 79,
                "total" => 3954,
              ],
          ],
          2 =>  [
          "text_response" => "I 've created a new Simple Text component for you. The component allows you to customize the text content, size, and color. You can now add it to your Drupal Canvas layout and configure it according to your needs.",
            "provider_name" => "litellm",
            "model_name" => "anthropic.claude-3-7-sonnet",
            "provider_request_time" => 1763457714.1588,
            "provider_response_time" => 1763457722.1882,
            "token_usage" =>  [
              "input" => 7291,
              "output" => 50,
              "total" => 7341,
            ],
          ],
        ],
        "sub_agent" =>  [
          "canvas_component_agent" =>  [
            1 =>  [
              "name" => "Drupal Canvas Component Agent",
              "agent_id" => "canvas_component_agent",
              "provider_name" => "litellm",
              "model_name" => "anthropic.claude-3-7-sonnet",
              "request_time" => 1763457703.3333,
              "provider_response_time" => 1763457714.1142,
              "token_usage" =>  [
              "input" => 7041,
                "output" => 545,
                "total" => 7586,
              ],
            ],
            2 =>  [
              "name" => "Drupal Canvas Component Agent",
              "agent_id" => "canvas_component_agent",
              "provider_name" => "litellm",
              "model_name" => "anthropic.claude-3-7-sonnet",
              "request_time" => 1763457714.1588,
              "provider_response_time" => 1763457714.1142,
              "token_usage" =>  [
                "input" => 7611,
                "output" => 53,
                "total" => 7664,
              ],
            ],
          ],
        ],
        "tools_used" =>  [
          0 =>  [
            "agent_id" => "canvas_ai_orchestrator",
            "tool_id" => "canvas_component_agent",
            "input" => '{"prompt": "Create a simple text component."}',
            "tool_feedback_message" => "",
            "time" => 1763457703.3128,
          ],
        ],
        "end_time" => 1763457722.2011,
        "total_time" => 23.34250020980835,
        "sub_agents" =>  [
          0 => "canvas_component_agent"
        ],
      ],
      'user_prompt' => 'Create a simple text component.',
      "user_id" => Crypt::hashBase64($user_id),
      "site_id" => Crypt::hashBase64($site_id),
      "status" => TRUE,
      "message" => NULL,
      "id" => Crypt::hashBase64("{$site_id}_$user_id"),
    ];
    $mock->method('info')
      ->willReturnCallback(function (string $message) use($expected_log_data) {
        $logs = json_decode($message, TRUE);
        $this->assertSame($expected_log_data, $logs);
      });
    $this->container->get('canvas_ai.logs.log_data_subscriber')->setLogger($mock);
    $uuid = $this->container->get('uuid')->generate();
    $items = [
      [
        'type' => 'agent_started',
        'agent_id' => $primary_agent_id,
        'agent_name' => $primary_agent_name,
        'agent_runner_id' => $uuid,
        'time' => 1763457698.8586,
      ],
      [
        'type' => 'text_generated',
        'time' => 1763457703.3128,
        'agent_id' => $primary_agent_id,
        'agent_name' => $primary_agent_name,
        'agent_runner_id' => $uuid,
        'loop_count' => 1,
        'text_response' => "I 'll help you create a simple text component. Let me do that for you right away.",
      ],
      [
        'type' => 'text_generated',
        'time' => 1763457722.1882,
        'agent_id' => $primary_agent_id,
        'agent_name' => $primary_agent_name,
        'agent_runner_id' => $uuid,
        'loop_count' => 2,
        'text_response' => "I 've created a new Simple Text component for you. The component allows you to customize the text content, size, and color. You can now add it to your Drupal Canvas layout and configure it according to your needs.",
      ],
      [
        'type' => 'text_generated',
        'time' => 1763457714.1142,
        'agent_id' => $sub_agents[$sub_agents_names[0]]['id'],
        'agent_name' => $sub_agents[$sub_agents_names[0]]['name'],
        'agent_runner_id' => $runner_id,
        'loop_count' => 1,
        'text_response' => "I 'll help you create a simple text component for Drupal Canvas. Let me create a component with basic text functionality.",
        'calling_agent_id' => $primary_agent_id,
      ],
      [
        'type' => 'text_generated',
        'time' => 1763457718.5293,
        'agent_id' => $sub_agents[$sub_agents_names[0]]['id'],
        'agent_name' => $sub_agents[$sub_agents_names[0]]['name'],
        'agent_runner_id' => $runner_id,
        'loop_count' => 2,
        'text_response' => "I 've created a Simple Text component for you. The component allows you to customize the text content, size, and color. You can now add this component to your Drupal Canvas layout and configure it with your desired text and styling options.",
        'calling_agent_id' => $primary_agent_id,
      ],
      [
        'type' => 'tool_selected',
        'time' => 1763457703.3128,
        'agent_id' => $primary_agent_id,
        'agent_name' => $primary_agent_name,
        'agent_runner_id' => $runner_id,
        'tool_name' => 'canvas_component_agent',
        'tool_input' => '{"prompt": "Create a simple text component."}',
        'calling_agent_id' => $primary_agent_id,
        'tool_id' => "tooluse_{$this->getRandomGenerator()->string()}",
        'tool_feedback_message' => '',
      ],
      [
        'type' => 'agent_finished',
        'time' => 1763457722.2011,
        'agent_id' => $primary_agent_id,
        'agent_name' => $primary_agent_name,
        'agent_runner_id' => $runner_id,
      ],
      [
        'type' => 'ai_provider_request',
        'time' => 1763457703.3333,
        'agent_id' => $sub_agents[$sub_agents_names[0]]['id'],
        'agent_name' => $sub_agents[$sub_agents_names[0]]['name'],
        'agent_runner_id' => $runner_id,
        'loop_count' => 1,
        'calling_agent_id' => $runner_id,
        'provider_name' => 'litellm',
        'model_name' => 'anthropic.claude-3-7-sonnet',
      ],
      [
        'type' => 'ai_provider_request',
        'time' => 1763457714.1588,
        'agent_id' => $sub_agents[$sub_agents_names[0]]['id'],
        'agent_name' => $sub_agents[$sub_agents_names[0]]['name'],
        'agent_runner_id' => $runner_id,
        'loop_count' => 2,
        'calling_agent_id' => $runner_id,
        'provider_name' => 'litellm',
        'model_name' => 'anthropic.claude-3-7-sonnet',
      ],
      [
        'type' => 'ai_provider_request',
        'time' => 1763457698.8718,
        'agent_id' => $primary_agent_id,
        'agent_name' => $primary_agent_name,
        'agent_runner_id' => $runner_id,
        'loop_count' => 1,
        'provider_name' => 'litellm',
        'model_name' => 'anthropic.claude-3-7-sonnet',
        'request_data' => [
          'messages' => [
            [
              'role' => 'user',
              'text' => 'Create a simple text component.',
            ],
          ],
        ]
      ],
      [
        'type' => 'ai_provider_request',
        'time' => 1763457714.1588,
        'agent_id' => $primary_agent_id,
        'agent_name' => $primary_agent_name,
        'agent_runner_id' => $runner_id,
        'loop_count' => 2,
        'provider_name' => 'litellm',
        'model_name' => 'anthropic.claude-3-7-sonnet',
      ],
      [
        'type' => 'ai_provider_response',
        'time' => 1763457703.3533,
        'agent_id' => $primary_agent_id,
        'agent_name' => $primary_agent_name,
        'loop_count' => 1,
        'response_data' => [
          'tokenUsage' => [
            'input' => 3875,
            'output' => 79,
            'total' => 3954,
          ],
        ],
        'agent_runner_id' => $runner_id,
      ],
      [
        'type' => 'ai_provider_response',
        'time' => 1763457722.1882,
        'agent_id' => $primary_agent_id,
        'agent_name' => $primary_agent_name,
        'loop_count' => 2,
        'response_data' => [
          'tokenUsage' => [
            'input' => 7291,
            'output' => 50,
            'total' => 7341,
          ],
        ],
        'agent_runner_id' => $runner_id,
      ],
      [
        'type' => 'ai_provider_response',
        'time' => 1763457714.1142,
        'agent_id' => $sub_agents[$sub_agents_names[0]]['id'],
        'agent_name' => $sub_agents[$sub_agents_names[0]]['name'],
        'loop_count' => 1,
        'response_data' => [
          'tokenUsage' => [
            'input' => 7041,
            'output' => 545,
            'total' => 7586,
          ],
        ],
        'agent_runner_id' => $runner_id,
        'calling_agent_id' => $primary_agent_id,
      ],
      [
        'type' => 'ai_provider_response',
        'time' => 1763457714.1142,
        'agent_id' => $sub_agents[$sub_agents_names[0]]['id'],
        'agent_name' => $sub_agents[$sub_agents_names[0]]['name'],
        'loop_count' => 2,
        'response_data' => [
          'tokenUsage' => [
            'input' => 7611,
            'output' => 53,
            'total' => 7664,
          ],
        ],
        'agent_runner_id' => $runner_id,
        'calling_agent_id' => $primary_agent_id,
      ],
    ];
    $ai_prompt_status_update = AiPromptStatusUpdate::fromArray(['items' => $items]);
    $ai_prompt_status_update->setStatus(TRUE);
    $event = new PrepareAiLogEvent($ai_prompt_status_update);
    $dispatcher->dispatch($event, CanvasAiLogEvents::PREPARE_LOGS);

    $log_array = $event->getLogs();
    $log_data_event = new LogAiPromptEvent($log_array);
    $dispatcher->dispatch($log_data_event, CanvasAiLogEvents::LOG);
  }

}
