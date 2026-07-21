<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Traits;

use Drupal\canvas_test_field_view_access\Hook\CanvasTestFieldViewAccessHooks;
use Drupal\Core\Cache\Cache;
use Drupal\Core\State\StateInterface;

/**
 * Helps tests deny "view" access to individual entity fields.
 *
 * Requires the canvas_test_field_view_access module to be installed.
 *
 * @see \Drupal\canvas_test_field_view_access\Hook\CanvasTestFieldViewAccessHooks
 */
trait DeniedFieldViewAccessTrait {

  /**
   * Denies "view" access to a field on an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID (for example, "canvas_page" or "node").
   * @param string $field_name
   *   The field name (for example, "components").
   */
  protected function denyFieldViewAccess(string $entity_type_id, string $field_name): void {
    $state = $this->container->get('state');
    \assert($state instanceof StateInterface);

    $denied = $state->get(CanvasTestFieldViewAccessHooks::STATE_KEY, []);
    \assert(\is_array($denied));
    $denied[] = $entity_type_id . ':' . $field_name;
    $state->set(CanvasTestFieldViewAccessHooks::STATE_KEY, \array_values(\array_unique($denied)));

    // Invalidate any cached access results that depend on this module's tag.
    Cache::invalidateTags(['canvas_test_field_view_access']);
  }

}
