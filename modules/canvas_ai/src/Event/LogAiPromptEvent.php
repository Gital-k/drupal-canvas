<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event that logs data.
 */
class LogAiPromptEvent extends Event {

  /**
   * Constructs a new LogAiPromptEvent.
   *
   * @param array $logs
   *   The data to log.
   */
  public function __construct(
    public readonly array $logs,
  ) {}

  /**
   * Gets the log data.
   *
   * @return array
   *   The log data.
   */
  public function getLogs(): array {
    return $this->logs;
  }

}
