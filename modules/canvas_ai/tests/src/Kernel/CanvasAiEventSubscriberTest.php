<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

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
    'canvas',
    'canvas_ai',
    'key',
    'ai',
    'ai_agents',
    'dblog',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('dblog', ['watchdog']);
  }

  /**
   * Tests event subscribers.
   */
  public function testEventSubscribers() {
    $expected_request_id = 'test-request-1';
    $agent = NULL;
    $prompt = ['request_id' => $expected_request_id];
    $current_user = $this->container->get('current_user');
    $site_config = $this->container->get('config.factory')->get('system.site');
    $error_message = NULL;

    $dispatcher = $this->container->get('event_dispatcher');
    $event = new PrepareAiLogEvent($agent, $prompt, $current_user, $site_config, $error_message);
    $dispatcher->dispatch($event, PrepareAiLogEvent::class);

    $log_array = $event->getLogArray();
    $this->assertNotEmpty($log_array, 'Log array was set by PrepareAiLogEvent.');
    $this->assertEquals($expected_request_id, $log_array['request_id']);

    $log_data_event = new LogAiPromptEvent($log_array);
    $this->assertNotEmpty($log_data_event, 'Log array was set by LogAiPromptEvent.');
    $dispatcher->dispatch($log_data_event, LogAiPromptEvent::class);

    // Select the most recent log entry of type 'canvas_ai'.
    $log_entry = $this->container->get('database')
      ->select('watchdog', 'w')
      ->fields('w', ['variables'])
      ->condition('w.type', 'canvas_ai')
      ->orderBy('wid', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    $this->assertNotEmpty($log_entry, 'Log entry is not null.');

    $this->assertNotEmpty($log_entry, 'A log entry of type "canvas_ai" was found in the watchdog table.');
  }

}
