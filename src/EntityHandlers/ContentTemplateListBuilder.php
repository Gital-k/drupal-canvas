<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Form\ContentTemplateListFilterForm;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lists content templates in the CMS back end, next to Canvas pages.
 *
 * Content templates are created and edited in the Canvas UI. This listing
 * exists so users who look for them in the CMS back end can find them.
 */
final class ContentTemplateListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * Filtering happens after loading, so paging first would hide matches.
   */
  protected $limit = FALSE;

  private FormBuilderInterface $formBuilder;

  private RequestStack $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    $instance = parent::createInstance($container, $entity_type);
    \assert($instance instanceof self);
    $instance->formBuilder = $container->get(FormBuilderInterface::class);
    $instance->requestStack = $container->get(RequestStack::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    $query = $this->requestStack->getCurrentRequest()?->query;
    $search = mb_strtolower(trim((string) $query?->get(ContentTemplateListFilterForm::SEARCH, '')));
    $status = (string) $query?->get(ContentTemplateListFilterForm::STATUS, '');

    // Labels are computed from the target bundle and view mode, so they cannot
    // be matched with an entity query.
    return array_filter(parent::load(), static function (EntityInterface $entity) use ($search, $status): bool {
      \assert($entity instanceof ContentTemplate);
      if ($search !== '' && !str_contains(mb_strtolower((string) $entity->label()), $search)) {
        return FALSE;
      }
      return $status === '' || (int) $entity->status() === (int) $status;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#cache']['contexts'][] = 'url.query_args:' . ContentTemplateListFilterForm::SEARCH;
    $build['table']['#cache']['contexts'][] = 'url.query_args:' . ContentTemplateListFilterForm::STATUS;
    $query = $this->requestStack->getCurrentRequest()?->query;
    if ($query?->has(ContentTemplateListFilterForm::SEARCH) || $query?->has(ContentTemplateListFilterForm::STATUS)) {
      $build['table']['#empty'] = $this->t('No content templates match the filters.');
    }
    return ['filters' => $this->formBuilder->getForm(ContentTemplateListFilterForm::class)] + $build;
  }

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
  public function getDefaultOperations(EntityInterface $entity): array {
    \assert($entity instanceof ContentTemplate);
    $operations = parent::getDefaultOperations($entity);

    // Content templates have no Drupal edit form: the Canvas UI edits them.
    // @see \Drupal\canvas\Controller\ViewModeDisplayController
    if ($entity->access('update')) {
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
