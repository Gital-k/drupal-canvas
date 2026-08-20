<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Plugin\ComponentPluginManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Canvas's class-keyed SDC alias must survive decoration of the service.
 *
 * CanvasServiceProvider re-classes plugin.manager.sdc and registers a
 * class-keyed alias for it. When any other module DECORATES that service —
 * ui_patterns is the best-known example, but the test decorator here is
 * deliberately Canvas-unaware — the public id resolves to the outermost
 * decorator, and without the fix the alias serves an object that is not an
 * instance of Canvas's ComponentPluginManager. Every typed PropShape call
 * then throws a TypeError; because the first one fires from
 * hook_modules_installed, the modules are already recorded as installed and
 * every subsequent cache rebuild fatals while cached pages keep serving.
 *
 * This test never calls generateComponents() — that is the call that
 * fatals — it asserts the container wiring the fix corrects: the class
 * alias must yield Canvas's own manager (the decorated inner instance),
 * decorator or no decorator.
 *
 * @see https://www.drupal.org/project/canvas/issues/3561618
 */
#[Group('canvas')]
final class SdcDecoratorCoexistenceTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_sdc_decorator',
  ];

  /**
   * The class alias yields Canvas's manager even when the id is decorated.
   */
  public function testClassAliasSurvivesDecoration(): void {
    // The decorator really is the outermost service on the public id —
    // otherwise this test would pass vacuously with no decoration in play.
    // The string id is the point here: this lookup and the class-keyed one
    // below MUST be allowed to differ for the assertion to mean anything.
    // phpcs:ignore Canvas.Services.ClassServiceId.StringServiceId
    $public = $this->container->get('plugin.manager.sdc');
    $this->assertInstanceOf(
      'Drupal\canvas_test_sdc_decorator\DecoratingComponentPluginManager',
      $public,
      'The test decorator wraps plugin.manager.sdc.',
    );

    // The assertion the fix exists for: Canvas's own consumers resolve the
    // manager by class, and that lookup must reach Canvas's instance (the
    // decorated inner service), never the decorator.
    $byClass = $this->container->get(ComponentPluginManager::class);
    $this->assertInstanceOf(ComponentPluginManager::class, $byClass);
  }

}
