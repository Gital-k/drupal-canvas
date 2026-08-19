<?php

declare(strict_types=1);

namespace Drupal\canvas_test_sdc_decorator;

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
 * The constructor intentionally does not call the parent: this decorator
 * exists to be resolved from the container in a test assertion, never to
 * serve plugin queries.
 */
final class DecoratingComponentPluginManager extends ComponentPluginManager {

  /**
   * Constructs a DecoratingComponentPluginManager.
   *
   * @param \Drupal\Core\Theme\ComponentPluginManager $inner
   *   The decorated manager.
   */
  public function __construct(
    protected readonly ComponentPluginManager $inner,
  ) {}

}
