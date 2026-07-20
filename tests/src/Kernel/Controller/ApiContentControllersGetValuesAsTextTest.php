<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the ApiContentControllers::getValuesAsText() method.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiContentControllers::class)]
#[CoversMethod(ApiContentControllers::class, 'getValuesAsText')]
class ApiContentControllersGetValuesAsTextTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
  ];

  private const string URL = '/canvas/api/v0/content/canvas_page/values-as-text/%s';

  private Page $page;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installConfig(['system', 'field', 'filter', 'path_alias']);

    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION, Page::EDIT_PERMISSION]);

    $page = Page::create([
      'title' => 'Page 1',
      'status' => TRUE,
      'path' => ['alias' => '/page-1'],
      'components' => [],
    ]);
    $page->save();
    \assert($page instanceof Page);
    \assert(!\is_null($page->getRevisionCreationTime()));
    $this->page = $page;
  }

  /**
   * Tests requesting the auto-saved text when no auto-save item exists.
   */
  public function testGetAutoSavedValuesAsTextWithoutAutoSaveReturnsNotFound(): void {
    $response = $this->request(Request::create(\sprintf(self::URL, $this->page->id())));
    self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

    $response_body = $this->decodeResponse($response);
    self::assertSame([
      'errors' => [
        [
          'detail' => \sprintf('No auto-save items for entity %s found', $this->page->id()),
          'source' => [
            'pointer' => AutoSaveManager::getAutoSaveKey($this->page),
          ],
        ],
      ],
    ], $response_body);
  }

  /**
   * Tests requesting the published entity as text.
   */
  public function testGetPublishedValuesAsText(): void {
    $response = $this->request(Request::create(\sprintf(self::URL, $this->page->id()), 'GET', [
      ApiContentControllers::AUTO_SAVED_QUERY_KEY => 'false',
    ]));
    \assert($response instanceof CacheableJsonResponse);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    $response_body = $this->decodeResponse($response);
    self::assertArrayHasKey('data', $response_body);
    self::assertSame('en', $response_body['data']['language']);
    self::assertIsInt($response_body['data']['updated']);
    self::assertIsString($response_body['data']['entity']);
    self::assertStringContainsString('Page 1', $response_body['data']['entity']);
  }

  /**
   * Tests requesting the auto-saved entity as text.
   */
  public function testGetAutoSavedValuesAsText(): void {
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    $this->page->set('title', 'Auto-saved title');
    $auto_save_manager->saveEntity($this->page);

    $request_start = \time();
    $response = $this->request(Request::create(\sprintf(self::URL, $this->page->id())));
    \assert($response instanceof CacheableJsonResponse);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    $response_body = $this->decodeResponse($response);
    self::assertArrayHasKey('data', $response_body);
    self::assertSame('en', $response_body['data']['language']);
    self::assertIsInt($response_body['data']['updated']);
    self::assertGreaterThanOrEqual($request_start, $response_body['data']['updated']);
    self::assertLessThanOrEqual(\time(), $response_body['data']['updated']);
    self::assertIsString($response_body['data']['entity']);
    self::assertStringContainsString('Auto-saved title', $response_body['data']['entity']);
  }

  /**
   * Tests that the endpoint requires the page edit permission.
   */
  public function testGetRequiresPermission(): void {
    // Switch to a user without the required entity access permission.
    $this->setUpCurrentUser([], ['access content']);

    $response = $this->request(Request::create(\sprintf(self::URL, $this->page->id())));
    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Tests the components field is omitted when the user cannot view it.
   *
   * A user allowed to view the page still receives HTTP 200, but the
   * "components" field they cannot view is omitted from the response.
   *
   * @see \canvas_test_content_field_view_access_entity_field_access()
   */
  public function testGetValuesAsTextOmitsComponentsWithoutViewAccess(): void {
    // Add a component so the "components" field is populated in the response.
    $this->page->set('components', [
      [
        'uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'inputs' => ['heading' => 'Welcome'],
      ],
    ]);
    $this->page->save();

    // Before installing the field-access module, "components" is present.
    $response = $this->request(Request::create(\sprintf(self::URL, $this->page->id()), 'GET', [
      ApiContentControllers::AUTO_SAVED_QUERY_KEY => 'false',
    ]));
    \assert($response instanceof CacheableJsonResponse);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $entity_values = Yaml::decode($this->decodeResponse($response)['data']['entity']);
    self::assertArrayHasKey('components', $entity_values);

    // Install the module that denies "view" access to the "components" field.
    // The current user lacks the "view canvas page components" permission.
    $this->container->get('module_installer')->install(['canvas_test_content_field_view_access']);

    // Confirm the current user cannot view the "components" field.
    self::assertFalse($this->page->get('components')->access('view'));

    $response = $this->request(Request::create(\sprintf(self::URL, $this->page->id()), 'GET', [
      ApiContentControllers::AUTO_SAVED_QUERY_KEY => 'false',
    ]));
    \assert($response instanceof CacheableJsonResponse);
    // The request still succeeds.
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    // The inaccessible "components" field is omitted from the response.
    $entity_values = Yaml::decode($this->decodeResponse($response)['data']['entity']);
    self::assertArrayNotHasKey('components', $entity_values);
    // A viewable field remains present.
    self::assertArrayHasKey('title', $entity_values);
    self::assertSame('Page 1', $entity_values['title'][0]['value']);
  }

}
