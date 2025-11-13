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
    $mock->method('info')
      ->willReturnCallback(function (string $message) {
        $logs = json_decode($message, TRUE);
        // @todo Update expected data.
        $this->assertSame([], $logs, 'Log data matches expected values.');
      }
    );
    $this->container->get('canvas_ai.logs.log_data_subscriber')->setLogger($mock);

    $ai_prompt_status_update = AiPromptStatusUpdate::fromArray([
      'items' => [],
    ]);
    $event = new PrepareAiLogEvent($ai_prompt_status_update);
    $dispatcher->dispatch($event, CanvasAiLogEvents::PREPARE_LOGS);

    $log_array = $event->getLogs();
    $log_data_event = new LogAiPromptEvent($log_array);
    $dispatcher->dispatch($log_data_event, CanvasAiLogEvents::LOG);
  }

}
