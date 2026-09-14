<?php

declare(strict_types=1);

namespace Drupal\canvas\Push;

/**
 * The stage a push has reached, as reported by a push event.
 *
 * @see \Drupal\canvas\Event\PushEvent
 */
enum PushStatus {
  case Started;
  case Completed;
  case Failed;
}
