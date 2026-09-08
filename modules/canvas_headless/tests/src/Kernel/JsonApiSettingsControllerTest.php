<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\Controller\JsonApiSettingsController;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the public JSON:API settings controller.
 */
#[CoversClass(JsonApiSettingsController::class)]
#[Group('canvas_headless')]
#[RunTestsInSeparateProcesses]
final class JsonApiSettingsControllerTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'serialization',
    'custom_elements',
    'consumers',
    'simple_oauth',
    'canvas_headless',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
  }

  public function testGetIsAnonymousSafeAndCacheable(): void {
    $controller = JsonApiSettingsController::create($this->container);
    $response = $controller->get();
    self::assertSame(200, $response->getStatusCode());
    // The response depends only on the prefix, which is invalidated via the
    // jsonapi_extras config tag. It carries no URL-dependent data, so it must
    // not vary by the url.site cache context.
    self::assertSame(
      [],
      $response->getCacheableMetadata()->getCacheContexts(),
    );
    self::assertSame(
      ['config:jsonapi_extras.settings'],
      $response->getCacheableMetadata()->getCacheTags(),
    );
  }

  /**
   * The route must be reachable without authentication.
   */
  public function testRouteIsPublic(): void {
    $route_provider = \Drupal::service('router.route_provider');
    $route = $route_provider->getRouteByName('canvas_headless.jsonapi_settings');
    self::assertSame('/canvas/api/v0/headless/jsonapi-settings', $route->getPath());
    // Anonymous access must be allowed; the OAuth/UI gates of the site-data
    // endpoint must not apply here.
    self::assertSame('TRUE', $route->getRequirement('_access'));
    self::assertFalse($route->hasRequirement('_canvas_ui_access'));
    self::assertFalse($route->hasRequirement('_canvas_authentication_required'));
  }

}
