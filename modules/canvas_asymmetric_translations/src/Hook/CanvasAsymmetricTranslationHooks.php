<?php

namespace Drupal\canvas_asymmetric_translations\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\canvas_asymmetric_translations\PageTranslationHandler;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hooks to provide asymmetric translations functionality.
 */
class CanvasAsymmetricTranslationHooks {

  /**
   * Implements hook_entity_type_alter().
   */
  #[Hook('entity_type_alter')]
  public function setAsymmetricTranslationsHandler(array &$entity_types) {
    if (isset($entity_types[Page::ENTITY_TYPE_ID])) {
      $entity_types[Page::ENTITY_TYPE_ID]->setHandlerClass('translation', PageTranslationHandler::class);
    }
  }

}
