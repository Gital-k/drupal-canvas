<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that empty required props with format constraints return 422, not 500.
 *
 * @see https://www.drupal.org/project/canvas/issues/3587580
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiContentControllers::class)]
class ApiEmptyRequiredFormattedPropTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'sdc_test_all_props',
    'field',
  ];

  private const string PATCH_URL = '/canvas/api/v0/content/canvas_page/%s';

  private const string POST_URL = '/canvas/api/v0/content/canvas_page';

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

    $component_source_manager = \Drupal::service(ComponentSourceManager::class);
    \assert($component_source_manager instanceof ComponentSourceManager);
    $component_source_manager->generateComponents();
  }

  /**
   * Tests that PATCH with a null required URI prop returns 422, not 500.
   *
   * Sending null for a required prop with format=uri used to trigger an
   * AssertionError in UriSchemeAwareFormatConstraint::check(), causing a 500.
   */
  public function testPatchWithNullRequiredUriPropReturns422(): void {
    $page = Page::create([
      'title' => 'Test page',
      'status' => FALSE,
      'path' => ['alias' => '/test-page'],
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $component = Component::load('sdc.sdc_test_all_props.all-props');
    \assert($component instanceof Component);

    $response = $this->request(Request::create(
      \sprintf(self::PATCH_URL, $page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([
        'title' => 'Test page',
        'status' => FALSE,
        'path' => '/test-page',
        'components' => [
          [
            'uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            'component_id' => 'sdc.sdc_test_all_props.all-props',
            'component_version' => $component->getActiveVersion(),
            // Intentionally send null for a required prop with format=uri.
            // This used to cause a 500 error via an AssertionError in
            // UriSchemeAwareFormatConstraint::check().
            'inputs' => [
              'test_REQUIRED_string_format_uri' => NULL,
            ],
            'parent_uuid' => NULL,
            'slot' => NULL,
            'label' => NULL,
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    ));

    // Must return 422 Unprocessable Entity, not 500 Internal Server Error.
    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $data = $this->decodeResponse($response);
    $this->assertArrayHasKey('errors', $data);
  }

  /**
   * Tests that PATCH with a null required uri-reference prop returns 422.
   *
   * Sending null for a required prop with format=uri-reference used to trigger
   * an AssertionError in UriSchemeAwareFormatConstraint::check().
   */
  public function testPatchWithNullRequiredUriReferencePropReturns422(): void {
    $page = Page::create([
      'title' => 'Test page',
      'status' => FALSE,
      'path' => ['alias' => '/test-page-2'],
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $component = Component::load('sdc.sdc_test_all_props.all-props');
    \assert($component instanceof Component);

    $response = $this->request(Request::create(
      \sprintf(self::PATCH_URL, $page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([
        'title' => 'Test page',
        'status' => FALSE,
        'path' => '/test-page-2',
        'components' => [
          [
            'uuid' => 'b2c3d4e5-f6a7-8901-bcde-f12345678901',
            'component_id' => 'sdc.sdc_test_all_props.all-props',
            'component_version' => $component->getActiveVersion(),
            // Intentionally send null for a required prop with
            // format=uri-reference and x-allowed-schemes.
            'inputs' => [
              'test_REQUIRED_string_format_uri_reference_web_links' => NULL,
            ],
            'parent_uuid' => NULL,
            'slot' => NULL,
            'label' => NULL,
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    ));

    // Must return 422 Unprocessable Entity, not 500 Internal Server Error.
    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $data = $this->decodeResponse($response);
    $this->assertArrayHasKey('errors', $data);
  }

  /**
   * Tests that POST with a null required URI prop returns 422, not 500.
   */
  public function testPostWithNullRequiredUriPropReturns422(): void {
    $component = Component::load('sdc.sdc_test_all_props.all-props');
    \assert($component instanceof Component);

    $response = $this->request(Request::create(
      self::POST_URL,
      'POST',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([
        'title' => 'New page',
        'status' => FALSE,
        'path' => '/new-page',
        'components' => [
          [
            'uuid' => 'c3d4e5f6-a7b8-9012-cdef-123456789012',
            'component_id' => 'sdc.sdc_test_all_props.all-props',
            'component_version' => $component->getActiveVersion(),
            // Intentionally send null for a required prop with format=uri.
            'inputs' => [
              'test_REQUIRED_string_format_uri' => NULL,
            ],
            'parent_uuid' => NULL,
            'slot' => NULL,
            'label' => NULL,
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    ));

    // Must return 422 Unprocessable Entity, not 500 Internal Server Error.
    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $data = $this->decodeResponse($response);
    $this->assertArrayHasKey('errors', $data);
  }

}
