<?php

declare(strict_types=1);

namespace Drupal\canvas_test_field_view_access\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;

/**
 * Denies "view" access to individual fields for testing.
 *
 * Tests register the fields to deny by setting the state value keyed by
 * self::STATE_KEY to an array of "{entity_type_id}:{field_name}" strings. Any
 * field listed there is forbidden for the "view" operation, letting tests
 * assert that Canvas controllers omit fields the current user cannot view.
 *
 * This module is intentionally entity-type agnostic: it works for Page,
 * ContentTemplate previews, node bundles, or any other fieldable entity type.
 */
final class CanvasTestFieldViewAccessHooks {

  /**
   * State key holding the list of fields to deny "view" access to.
   *
   * The stored value is an array of "{entity_type_id}:{field_name}" strings.
   */
  public const string STATE_KEY = 'canvas_test_field_view_access.denied';

  public function __construct(
    private readonly StateInterface $state,
  ) {
  }

  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($operation !== 'view') {
      return AccessResult::neutral();
    }

    $denied = $this->state->get(self::STATE_KEY, []);
    \assert(\is_array($denied));

    $key = $field_definition->getTargetEntityTypeId() . ':' . $field_definition->getName();
    // Vary the cache on the state value so results are not incorrectly reused
    // after a test changes the set of denied fields.
    return AccessResult::forbiddenIf(
      condition: \in_array($key, $denied, TRUE),
      reason: \sprintf('Field "%s" view access is denied for testing.', $key),
    )->addCacheTags(['canvas_test_field_view_access']);
  }

}
