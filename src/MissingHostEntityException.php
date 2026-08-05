<?php

declare(strict_types=1);

namespace Drupal\canvas;

/**
 * Thrown by LinkablePropSourceInterface implementors.
 *
 * Thrown when the host entity is missing, and the prop source cannot be
 * evaluated.
 *
 * @todo Move to \Drupal\canvas\PropSource namespace.
 */
class MissingHostEntityException extends \Exception {

  public function __construct(string $message = "Missing host entity.", int $code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
  }

}
