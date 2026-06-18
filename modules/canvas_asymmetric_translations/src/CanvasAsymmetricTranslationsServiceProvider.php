<?php

namespace Drupal\canvas_asymmetric_translations;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\canvas\AutoSave\AutoSaveManager as OriginalAutoSaveManager;

/**
 * Modifies the canvas AutoSaveManager service.
 */
class CanvasAsymmetricTranslationsServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition(OriginalAutoSaveManager::class)) {
      $definition = $container->getDefinition(OriginalAutoSaveManager::class);
      $definition->setClass(AutoSaveManager::class);
    }
  }

}
