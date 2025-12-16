<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for Canvas template editor.
 */
class CanvasTemplateEditorController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Constructs a CanvasTemplateEditorController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityRepositoryInterface $entity_repository) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity.repository')
    );
  }

  /**
   * Renders the template editor view for a node.
   *
   * @param string $node_type
   *   The node type.
   * @param string $view_mode
   *   The view mode.
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   A render array.
   */
  public function view(string $node_type, string $view_mode, NodeInterface $node): array {
    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    if ($node->bundle() !== $node_type) {
      throw new NotFoundHttpException();
    }
    $build = $view_builder->view($node, $view_mode);

    return $build;
  }

  /**
   * The title callback for the template editor page.
   *
   * @param string $node_type
   *   The node type.
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function title(string $node_type, NodeInterface $node): TranslatableMarkup {
    $translated_node = $this->entityRepository->getTranslationFromContext($node);
    if ($node->bundle() !== $node_type) {
      throw new NotFoundHttpException();
    }
    return $this->t('Edit Content Template: @title', ['@title' => $translated_node->label()]);
  }

}
