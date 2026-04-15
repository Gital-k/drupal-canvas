<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_oauth\Kernel;

use Drupal\canvas_oauth\MediaScopesHelper;
use Drupal\media\Entity\MediaType;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that media image OAuth2 scopes are created correctly.
 *
 * Covers two scenarios from https://www.drupal.org/project/canvas/issues/3584714:
 * 1. canvas_oauth installed after media types already exist (hook_install path)
 * 2. A new image media type is created after canvas_oauth is installed
 *    (hook_media_type_insert path — covers recipe/config import order)
 */
#[CoversClass(MediaScopesHelper::class)]
#[Group('canvas_oauth')]
final class MediaScopesHelperTest extends CanvasKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'consumers',
    'canvas_oauth',
    'simple_oauth',
    'serialization',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('oauth2_scope');
    $this->installEntitySchema('media');
    $this->installConfig(['media']);
  }

  /**
   * Tests that ensureMediaImageScopes() creates a scope for existing image types.
   *
   * Simulates the case where canvas_oauth is installed after media types exist.
   */
  public function testScopeCreatedForExistingImageMediaType(): void {
    $media_type = $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);

    $scope_id = 'canvas_media_image_create';
    $this->assertNull(Oauth2Scope::load($scope_id), 'Scope does not exist before ensureMediaImageScopes().');

    \Drupal::classResolver(MediaScopesHelper::class)->ensureMediaImageScopes();

    $scope = Oauth2Scope::load($scope_id);
    $this->assertNotNull($scope, 'Scope was created by ensureMediaImageScopes().');
    $this->assertSame('canvas:media:image:create', $scope->getName());
    $dependencies = $scope->getDependencies();
    $this->assertSame(['canvas_oauth'], $dependencies['module']);
    $this->assertSame([$media_type->getConfigDependencyName()], $dependencies['config']);
  }

  /**
   * Tests that the scope is created when a new image media type is inserted.
   *
   * Simulates the case where canvas_oauth is installed before media types exist
   * (e.g. recipe/config import order), and a media type is created afterwards.
   */
  public function testScopeCreatedOnImageMediaTypeInsert(): void {
    $scope_id = 'canvas_media_image_create';
    $this->assertNull(Oauth2Scope::load($scope_id), 'Scope does not exist before media type is created.');

    // Creating the media type fires hook_media_type_insert, which should
    // trigger scope creation.
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);

    $scope = Oauth2Scope::load($scope_id);
    $this->assertNotNull($scope, 'Scope was created by hook_media_type_insert.');
    $this->assertSame('canvas:media:image:create', $scope->getName());
  }

  /**
   * Tests that non-image media types do not trigger scope creation.
   */
  public function testNoScopeCreatedForNonImageMediaType(): void {
    $this->createMediaType('file', ['id' => 'document', 'label' => 'Document']);

    $this->assertNull(
      Oauth2Scope::load('canvas_media_document_create'),
      'No scope is created for non-image media types.',
    );
  }

  /**
   * Tests that ensureMediaImageScopes() is idempotent.
   */
  public function testEnsureMediaImageScopesIsIdempotent(): void {
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);

    $helper = \Drupal::classResolver(MediaScopesHelper::class);
    $helper->ensureMediaImageScopes();
    $helper->ensureMediaImageScopes();

    $scopes = Oauth2Scope::loadMultiple();
    $image_scopes = array_filter(
      $scopes,
      static fn ($scope) => str_starts_with($scope->id(), 'canvas_media_image'),
    );
    $this->assertCount(1, $image_scopes, 'Only one scope exists after calling ensureMediaImageScopes() twice.');
  }

}
