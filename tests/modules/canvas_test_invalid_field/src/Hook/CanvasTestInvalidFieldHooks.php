<?php

declare(strict_types=1);

namespace Drupal\canvas_test_invalid_field\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for canvas_test_invalid_field.
 */
class CanvasTestInvalidFieldHooks {

  /**
   * Implements hook_entity_bundle_field_info_alter().
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function entityBundleFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    if ($entity_type->id() === 'node' && $bundle === 'article' && isset($fields['field_cvt_textfield'])) {
      $fields['field_cvt_textfield']->addConstraint('CanvasTestInvalidFieldConstraint', []);
    }
  }

}
