<?php

declare(strict_types=1);

namespace Drupal\canvas\Push;

/**
 * Status of a CL push.
 *
 * @see \Drupal\canvas\Event\PushEvent
 * @internal
 */
enum PushStatus {
  case Started;
  case Completed;
  case Failed;
}
