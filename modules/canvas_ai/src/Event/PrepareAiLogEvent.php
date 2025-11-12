<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Event;

use Drupal\canvas_ai\AgentStatus\AiPromptStatusUpdateInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched to prepare canvas AI logs.
 *
 * Subscribers can inspect, add, or modify the log data before it is logged.
 */
final class PrepareAiLogEvent extends Event {

  /**
   * The log data.
   *
   * @var array
   */
  protected array $logs;

  /**
   * Constructs a new AlterPromptDataEvent.
   *
   * @param \Drupal\canvas_ai\AgentStatus\AiPromptStatusUpdateInterface $aiPromptStatusUpdate
   *   The AI prompt status data.
   */
  public function __construct(
    private readonly AiPromptStatusUpdateInterface $aiPromptStatusUpdate,
  ) {}

  /**
   * Sets the log data.
   *
   * @param array $logs
   *   The log data.
   */
  public function setLogs(array $logs): void{
    $this->logs = $logs;
  }

  /**
   * Gets the log data.
   *
   * @return array
   *   The log data.
   */
  public function getLogs(): array {
    return $this->logs ?? [];
  }

  /**
   * Gets the AI prompt status data.
   *
   * @return \Drupal\canvas_ai\AgentStatus\AiPromptStatusUpdateInterface
   *   The AI prompt status data.
   */
  public function getPromptStatusUpdate(): AiPromptStatusUpdateInterface {
    return $this->aiPromptStatusUpdate;
  }

}
