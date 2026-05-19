<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\CanvasForm;
use Drupal\canvas\Plugin\Canvas\ComponentSource\CanvasFormComponent;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class CanvasFormStorage extends ConfigEntityStorage implements EntityHandlerInterface {

  private ComponentSourceManager $componentSourceManager;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    $instance = parent::createInstance($container, $entity_type);
    $instance->componentSourceManager = $container->get(ComponentSourceManager::class);
    return $instance;
  }

  protected function doPostSave(EntityInterface $entity, $update): void {
    parent::doPostSave($entity, $update);
    \assert($entity instanceof CanvasForm);
    $this->componentSourceManager->generateComponents(CanvasFormComponent::SOURCE_PLUGIN_ID, [(string) $entity->id()]);
  }

}
