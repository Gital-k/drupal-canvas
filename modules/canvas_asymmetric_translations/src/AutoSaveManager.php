<?php

namespace Drupal\canvas_asymmetric_translations;

use Drupal\canvas\AutoSave\AutoSaveManager as CanvasAutoSaveManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;

/**
 * Manages autosaves with an asymetric strategy.
 */
class AutoSaveManager extends CanvasAutoSaveManager {

  /**
   * {@inheritdoc}
   */
  protected function createEntityFromAutoSave(array $entry) {
    $entity = parent::createEntityFromAutoSave($entry);
    $original_entity = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->load($entity->id());
    if ($entity instanceof ContentEntityInterface && $entity->isTranslatable() && $original_entity instanceof ContentEntityInterface) {
      $entity = $this->updateTranslatableEntity($original_entity, $entity);
    }
    return $entity;
  }

  /**
   * Updates a translatable entity.
   *
   * @param \Drupal\Core\Entity\TranslatableInterface $entity
   *   Entity.
   * @param \Drupal\Core\Entity\TranslatableInterface $autosave_entity
   *   Autosave entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Created entity.
   */
  protected function updateTranslatableEntity(TranslatableInterface $entity, TranslatableInterface $autosave_entity) {
    // Temporary prevent changing the language, so translation creation can
    // be stabilized first. Additionally, the changed field
    // is omitted as it should be not given by client.
    $autosave_values = array_filter($autosave_entity->toArray(), function ($field_name) use ($entity) {
      if (in_array($field_name, [$entity->getEntityType()->getKey('langcode'), 'default_langcode', 'changed'])) {
        return FALSE;
      }
      return TRUE;
    }, ARRAY_FILTER_USE_KEY);

    // Loads the content relative to the autosave entity language.
    $entity = $entity->hasTranslation($autosave_entity->language()->getId()) ? $entity->getTranslation($autosave_entity->language()->getId()) : $entity;
    if ($entity->language()->getId() == $autosave_entity->language()->getId()) {
      foreach ($autosave_values as $field_name => $value) {
        $entity->{$field_name} = $value;
      }
    }
    else {
      $entity = $entity->addTranslation($autosave_entity->language()->getId(), $autosave_values);
    }
    return $entity;
  }

}
