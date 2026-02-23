<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\views\field;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * A field handler that displays the entity label.
 *
 * This handler calls the entity's label() method instead of accessing
 * the field value directly. This is useful when the entity's label()
 * method contains custom logic (e.g., returning auto-save values for
 * unpublished entities).
 *
 * @see \Drupal\canvas\Entity\Page::label()
 */
#[ViewsField("canvas_entity_label_field")]
class EntityLabelField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): array {
    $entity = $this->getEntity($values);
    if (!$entity instanceof EntityInterface) {
      return [];
    }

    $label = $entity->label();

    // Support linking to the entity.
    if (!empty($this->options['link_to_entity'])) {
      try {
        $url = $entity->toUrl();
        return [
          '#type' => 'link',
          '#title' => $label,
          '#url' => $url,
        ];
      }
      catch (\Exception) {
        // If the entity doesn't have a canonical URL, just return the label.
        return ['#markup' => $label];
      }
    }

    return ['#markup' => $label];
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['link_to_entity'] = ['default' => FALSE];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);

    $form['link_to_entity'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link to entity'),
      '#default_value' => $this->options['link_to_entity'],
      '#description' => $this->t('Make entity label a link to the entity page.'),
    ];
  }

}
