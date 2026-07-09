<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Tmgmt;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor;
use Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the TMGMT translatables extractor for component inputs.
 *
 * Covers cases where the concrete config schema the extractor derives for a
 * component input does not match the shape of the stored (optimized) input
 * value. Extraction must tolerate the mismatch rather than crash.
 *
 * @see \Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor
 * @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor
 */
#[CoversClass(ComponentInputsTranslatablesExtractor::class)]
#[CoversClass(ComponentTreeFieldProcessor::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
#[RunTestsInSeparateProcesses]
final class ComponentInputsTranslatablesExtractorTest extends CanvasKernelTestBase {

  use DataProviderWithComponentTreeTrait;
  use GenerateComponentConfigTrait;
  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * The test SDC: has a translatable `heading` string and an `image` object.
   */
  private const string COMPONENT_ID = 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop';

  private const string INSTANCE_UUID = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'language',
    'content_translation',
    'tmgmt',
    'tmgmt_content',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('media');
    $this->installEntitySchema('file');
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('file', ['file_usage']);

    // An image media type makes an `$ref image` prop resolve to an
    // entity_reference static prop source — the shape that triggers the bug.
    // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
    $this->createMediaType('image', ['id' => 'image']);

    // Generate the test SDC Component config entities now that an image media
    // type exists, so the `image` prop is backed by entity_reference.
    $this->generateComponentConfig();

    // Resolving the media reference (via `inputs_resolved`) checks access.
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * A collapsed entity_reference input must be skipped, not crash extraction.
   *
   * A prop that is `type: object` (`$ref canvas.module/image`) is backed by a
   * cardinality-1 `entity_reference` static prop source. Its
   * `field.value.entity_reference` config schema has two keys (`target_id`,
   * `target_uuid`), so the extractor derives a `mapping` schema for it. Canvas,
   * however, collapses a cardinality-1 entity_reference to the bare `target_id`
   * scalar (a media ID string) — the canonical form produced by content import
   * and `optimizeInputs()`. Recursing into that scalar with the array-typed
   * schema used to throw a TypeError.
   */
  public function testExtractSkipsCollapsedEntityReferenceImage(): void {
    // Create the referenced image media entity.
    $file = File::create(['uri' => 'public://test.png']);
    $file->setPermanent();
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Test image',
      'field_media_image' => ['target_id' => $file->id()],
    ]);
    $media->save();

    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    // The `image` input is a bare media ID string: the canonical collapsed form
    // of a cardinality-1 entity_reference, exactly what Canvas content import
    // (CANVAS_ENTITY_REFERENCE) and `optimizeInputs()` produce.
    $page = $storage->create([
      'title' => 'Test page',
      'components' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => self::INSTANCE_UUID,
          'component_id' => self::COMPONENT_ID,
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [
            'heading' => 'Hello world',
            // Bare media ID (collapsed entity_reference).
            'image' => (string) $media->id(),
          ],
        ],
      ]),
    ]);
    \assert($page instanceof ContentEntityInterface);
    // Save so the `inputs` field property is stored as JSON, exactly as the
    // TMGMT field processor reads it in production.
    $page->save();

    $processor = ComponentTreeFieldProcessor::create($this->container);
    // Before the fix this throws a TypeError: the extractor builds a `mapping`
    // schema for `image` and recurses into the scalar media ID.
    $data = $processor->extractTranslatableData($page->get('components'));

    // The translatable string prop is offered for translation.
    self::assertArrayHasKey(0, $data);
    self::assertArrayHasKey('heading', $data[0]);
    self::assertSame('Hello world', $data[0]['heading']['#text']);
    self::assertTrue($data[0]['heading']['#translate']);

    // The media reference has nothing translatable and must be skipped.
    self::assertArrayNotHasKey('image', $data[0]);
  }

}
