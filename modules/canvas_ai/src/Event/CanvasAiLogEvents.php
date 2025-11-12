<?php

namespace Drupal\canvas_ai\Event;

/**
 * Defines event names for Canvas AI prompt logging.
 */
final class CanvasAiLogEvents {

  /**
   * Event fired to prepare Canvas AI prompt status log data.
   *
   * Modules can subscribe to this event to inspect, add, or modify log entries
   * before they are finalized. The event object provides access to the current
   * log data and AI Agent prompt status object.
   *
   * @Event
   *
   * @var string
   */
  public const PREPARE_LOGS = 'canvas_ai.prepare_logs';

  /**
   * Event fired to log Canvas AI prompt data using custom implementations.
   *
   * Module developers can subscribe to this event to handle logging according to
   * their requirements, such as sending logs to third-party endpoints or storing
   * them in custom tables. Subscribers receive a readonly array of data that
   * needs to be logged.
   *
   * @Event
   *
   * @var string
   */
  public const LOG = 'canvas_ai.log';

}
