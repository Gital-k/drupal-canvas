<?php

namespace Drupal\canvas_ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event that logs data.
 */
class LogAiPromptEvent extends Event {

  /**
   * The data to log.
   *
   * @var mixed
   */
  protected $logData;

  /**
   * Constructs a new LogAiPromptEvent.
   *
   * @param mixed $logData
   *   The data to log.
   */
  public function __construct($logData) {
    $this->logData = $logData;
  }

  /**
   * Gets the log data.
   *
   * @return mixed
   *   The log data.
   */
  public function getLogData() {
    return $this->logData;
  }
}

