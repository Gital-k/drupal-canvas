<?php

declare(strict_types=1);

namespace Drupal\canvas_force_publish_error\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for canvas_force_publish_error.
 */
class CanvasForcePublishErrorHooks {

  /**
   * Implements hook_entity_presave().
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    // Check if entity has a title/label and it contains "cause exception".
    $label = $entity->label();
    if ($label && stripos((string) $label, 'cause exception') !== FALSE) {
      throw new EntityStorageException('Forced exception for testing purposes.');
    }
  }

}
