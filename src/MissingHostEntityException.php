<?php

declare(strict_types=1);

namespace Drupal\canvas;

/**
 * Thrown when a prop source is evaluated without the host entity it needs.
 */
class MissingHostEntityException extends \Exception {

  public function __construct(string $message = "Missing host entity.", int $code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
  }

}
