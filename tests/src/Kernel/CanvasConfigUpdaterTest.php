<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Entity\PageRegion;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;

/**
 * Tests the CanvasConfigUpdater service.
 *
 * @group canvas
 * @coversDefaultClass \Drupal\canvas\CanvasConfigUpdater
 */
final class CanvasConfigUpdaterTest extends KernelTestBase {

  use GenerateComponentConfigTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'canvas',
    'canvas_test_sdc',
    'canvas_test_code_components',
    'field',
    'file',
    'image',
    'link',
    'media',
    'node',
    'options',
    'text',
    'filter',
    'datetime',
  ];

  /**
   * The CanvasConfigUpdater service.
   */
  private CanvasConfigUpdater $configUpdater;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
    $this->installEntitySchema('user');
    $this->installEntitySchema('media');
    $this->installEntitySchema('file');
    $this->installConfig(['field', 'media', 'image']);
    $this->installSchema('file', ['file_usage']);

    // Create an image media type with the necessary field.
    $this->createMediaType('image', ['id' => 'image']);

    // Create a test file and media entity.
    $file = File::create([
      'uri' => 'public://test.png',
      'filename' => 'test.png',
      'filemime' => 'image/png',
    ]);
    $file->save();

    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Test image',
      'field_media_image' => [
        'target_id' => $file->id(),
      ],
    ]);
    $media->save();

    $this->configUpdater = $this->container->get(CanvasConfigUpdater::class);
  }

  /**
   * Tests needsIntermediateDependenciesComponentUpdate() with reference expressions.
   *
   * @covers ::needsIntermediateDependenciesComponentUpdate
   */
  public function testNeedsIntermediateDependenciesWithReferenceFieldExpression(): void {
    // Create a Pattern with a component that uses ReferenceFieldTypePropExpression.
    // This uses the vanilla_image JS component which has an image input that
    // references a media entity via ReferenceFieldTypePropExpression.
    $pattern = Pattern::create([
      'id' => 'test_pattern_with_ref',
      'label' => 'Test pattern with reference expression',
      'component_tree' => [
        [
          'uuid' => '392fdd2a-354c-43cf-bb64-396a63217cb9',
          'component_id' => 'js.canvas_test_code_components_vanilla_image',
          'component_version' => 'b5e039b71b27fef6',
          'inputs' => [
            'image' => [
              'target_id' => '1',
            ],
          ],
        ],
      ],
    ]);
    $pattern->save();

    // This entity uses ReferenceFieldTypePropExpression, so it should need the update.
    $this->assertTrue(
      $this->configUpdater->needsIntermediateDependenciesComponentUpdate($pattern),
      'Pattern with ReferenceFieldTypePropExpression should need intermediate dependencies update'
    );
  }

  /**
   * Tests needsIntermediateDependenciesComponentUpdate() without reference expressions.
   *
   * @covers ::needsIntermediateDependenciesComponentUpdate
   */
  public function testNeedsIntermediateDependenciesWithoutReferenceFieldExpression(): void {
    // Create a Pattern with a component that uses only static values.
    // No reference field expressions are used.
    $pattern = Pattern::create([
      'id' => 'test_pattern_without_ref',
      'label' => 'Test pattern without reference expression',
      'component_tree' => [
        [
          'uuid' => 'ba123456-7890-4bcd-aef1-23456789abcd',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => '8dd7b865998f53b0',
          'inputs' => [
            'text' => 'Static heading text',
            'style' => 'secondary',
            'element' => 'h2',
          ],
        ],
      ],
    ]);
    $pattern->save();

    // This entity does NOT use reference field expressions, so it should NOT need the update.
    $this->assertFalse(
      $this->configUpdater->needsIntermediateDependenciesComponentUpdate($pattern),
      'Pattern without reference field expressions should NOT need intermediate dependencies update'
    );
  }

  /**
   * Tests needsIntermediateDependenciesComponentUpdate() with empty component tree.
   *
   * @covers ::needsIntermediateDependenciesComponentUpdate
   */
  public function testNeedsIntermediateDependenciesWithEmptyComponentTree(): void {
    // Create a Pattern with an empty component tree.
    $pattern = Pattern::create([
      'id' => 'test_pattern_empty',
      'label' => 'Test pattern with empty tree',
      'component_tree' => [],
    ]);
    $pattern->save();

    // Empty component tree should NOT need the update.
    $this->assertFalse(
      $this->configUpdater->needsIntermediateDependenciesComponentUpdate($pattern),
      'Pattern with empty component tree should NOT need intermediate dependencies update'
    );
  }

  /**
   * Tests needsIntermediateDependenciesComponentUpdate() with PageRegion.
   *
   * @covers ::needsIntermediateDependenciesComponentUpdate
   */
  public function testNeedsIntermediateDependenciesWithPageRegion(): void {
    $this->installConfig(['system']);

    // Create a PageRegion with reference field expression.
    $region_with_ref = PageRegion::create([
      'id' => 'stark.test_with_ref',
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => '492fdd2a-354c-43cf-bb64-396a63217cb9',
          'component_id' => 'js.canvas_test_code_components_vanilla_image',
          'component_version' => 'b5e039b71b27fef6',
          'inputs' => [
            'image' => [
              'target_id' => '1',
            ],
          ],
        ],
      ],
    ]);
    $region_with_ref->save();

    $this->assertTrue(
      $this->configUpdater->needsIntermediateDependenciesComponentUpdate($region_with_ref),
      'PageRegion with reference expression should need intermediate dependencies update'
    );

    // Create a PageRegion without reference field expression.
    $region_without_ref = PageRegion::create([
      'id' => 'stark.test_without_ref',
      'theme' => 'stark',
      'region' => 'sidebar_second',
      'component_tree' => [
        [
          'uuid' => 'ca123456-7890-4bcd-aef1-23456789abcd',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => '8dd7b865998f53b0',
          'inputs' => [
            'text' => 'Static sidebar heading',
            'style' => 'primary',
            'element' => 'h3',
          ],
        ],
      ],
    ]);
    $region_without_ref->save();

    $this->assertFalse(
      $this->configUpdater->needsIntermediateDependenciesComponentUpdate($region_without_ref),
      'PageRegion without reference expression should NOT need intermediate dependencies update'
    );
  }

}
