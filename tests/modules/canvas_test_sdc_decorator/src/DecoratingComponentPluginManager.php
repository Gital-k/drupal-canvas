<?php

declare(strict_types=1);

namespace Drupal\canvas_test_sdc_decorator;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Theme\ComponentPluginManager;

/**
 * A minimal decorator of plugin.manager.sdc.
 *
 * Stands in for any real module that decorates the SDC plugin manager
 * (ui_patterns being the best-known example). It deliberately extends the
 * CORE manager and not Canvas's subclass: a decorator in the wild has no
 * reason to know Canvas exists, which is exactly the situation Canvas's
 * class-keyed alias must survive.
 *
 * The constructor intentionally does not call the parent; every method a
 * kernel boot may reach delegates to the decorated inner manager instead of
 * running the parent's machinery on uninitialized state.
 */
final class DecoratingComponentPluginManager extends ComponentPluginManager {

  /**
   * Constructs a DecoratingComponentPluginManager.
   *
   * @param \Drupal\Core\Theme\ComponentPluginManager $inner
   *   The decorated manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   The discovery cache backend.
   */
  public function __construct(
    protected readonly ComponentPluginManager $inner,
    CacheBackendInterface $cacheBackend,
  ) {
    $this->setCacheBackend($cacheBackend, 'canvas_test_sdc_decorator_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return $this->inner->getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    return $this->inner->getDefinition($plugin_id, $exception_on_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return $this->inner->hasDefinition($plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []): Component {
    return $this->inner->createInstance($plugin_id, $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function find(string $component_id): Component {
    return $this->inner->find($component_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllComponents(): array {
    return $this->inner->getAllComponents();
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions(): void {
    $this->inner->clearCachedDefinitions();
  }

}
