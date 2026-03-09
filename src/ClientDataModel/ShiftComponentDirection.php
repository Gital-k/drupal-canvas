<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel;

enum ShiftComponentDirection: string {
  case Up = 'up';
  case Down = 'down';

  public function apply(array $children, int $delta): array {
    if ($this === self::Down) {
      if ($delta >= \count($children)) {
        // Can't move down any further.
        return $children;
      }
      return [
        // Any items before the moved item.
        ...\array_slice($children, 0, $delta),
        // The item below the moved item.
        ...\array_slice($children, $delta + 1, 1),
        // The moved item.
        $children[$delta],
        // Any items after the item below the moved item.
        ...\array_slice($children, $delta + 2),
      ];
    }
    if ($delta === 0) {
      // Can't shift up any further.
      return $children;
    }
    return [
      // The items before the moved item, except the item before the item.
      ...\array_slice($children, 0, $delta - 1),
      // The moved item.
      $children[$delta],
      // The item that was before the moved item.
      ...\array_slice($children, $delta - 1, 1),
      // The items that were after the moved item.
      ...\array_slice($children, $delta + 1),
    ];
  }

}
