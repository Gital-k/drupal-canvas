<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Lists content templates in the CMS back end, next to Canvas pages.
 *
 * Content templates are created and edited in the Canvas UI. This listing
 * exists so users who look for them in the CMS back end can find them.
 */
final class ContentTemplateListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Template');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    \assert($entity instanceof ContentTemplate);
    $row['label'] = $entity->label();
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    \assert($entity instanceof ContentTemplate);
    $cacheability ??= new CacheableMetadata();
    $operations = parent::getDefaultOperations($entity, $cacheability);

    // Content templates have no Drupal edit form: the Canvas UI edits them.
    // @see \Drupal\canvas\Controller\ViewModeDisplayController
    $update_access = $entity->access('update', return_as_object: TRUE);
    $cacheability->addCacheableDependency($update_access);
    if ($update_access->isAllowed()) {
      $url = Url::fromUri(\sprintf(
        'base:canvas/template/%s/%s/%s',
        $entity->getTargetEntityTypeId(),
        $entity->getTargetBundle(),
        $entity->getMode(),
      ));
      $url->setOption('attributes', [
        'aria-label' => $this->t('Edit @entity_label', ['@entity_label' => $entity->label()]),
      ]);
      $operations['edit'] = [
        'title' => $this->t('Edit'),
        'weight' => 10,
        'url' => $url,
      ];
    }

    return $operations;
  }

}
