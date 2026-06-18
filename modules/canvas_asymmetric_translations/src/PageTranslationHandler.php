<?php

namespace Drupal\canvas_asymmetric_translations;

use Drupal\content_translation\ContentTranslationHandler;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines the translation handler for pages with custom canvas redirection.
 */
class PageTranslationHandler extends ContentTranslationHandler {

  /**
   * {@inheritdoc}
   */
  public function entityFormSubmit($form, FormStateInterface $form_state) {
    parent::entityFormSubmit($form, $form_state);

    /** @var \Drupal\Core\Entity\ContentEntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $form_object->getEntity();

    if ($entity->isNewTranslation()) {
      $url = $entity->toUrl('edit-form');
      $form_state->setRedirectUrl($url);
    }
  }

}
