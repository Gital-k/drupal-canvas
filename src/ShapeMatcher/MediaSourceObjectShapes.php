<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\media\Entity\MediaType;
use Drupal\media\MediaTypeInterface;
use Drupal\media\Plugin\media\Source\AudioFile;
use Drupal\media\Plugin\media\Source\File;
use Drupal\media\Plugin\media\Source\Image;
use Drupal\media\Plugin\media\Source\VideoFile;

/**
 * Maps Canvas object shapes to media source plugins and their media types.
 *
 * The media library integration creates fields targeting these media types
 * (static prop sources), and the entity field matcher suggests existing media
 * reference fields (dynamic prop sources). Both derive their matches from
 * this single mapping, so a media type usable in one context is usable in the
 * other.
 *
 * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
 * @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher
 *
 * @internal
 */
final class MediaSourceObjectShapes {

  const SCHEMA_TO_MEDIA_SOURCE = [
    // @see \Drupal\media\Plugin\media\Source\Image
    JsonSchemaObjectRef::Image->value => Image::class,
    // @see \Drupal\media\Plugin\media\Source\VideoFile
    JsonSchemaObjectRef::Video->value => VideoFile::class,
    // @see \Drupal\media\Plugin\media\Source\File
    JsonSchemaObjectRef::Document->value => File::class,
  ];

  /**
   * Media source classes that extend a mapped source but match no schema.
   *
   * AudioFile extends File, but audio files are not documents. Without this
   * list, media types using these sources would match the schema mapped to
   * their parent class in SCHEMA_TO_MEDIA_SOURCE.
   */
  const UNSUPPORTED_MEDIA_SOURCE_SUBCLASSES = [
    // @see \Drupal\media\Plugin\media\Source\AudioFile
    AudioFile::class,
  ];

  /**
   * @param class-string $media_source_class
   *
   * @return array<string, \Drupal\media\MediaTypeInterface>
   */
  public static function getMediaTypesForSource(string $media_source_class) : array {
    // Allow all MediaTypes that use the "image" MediaSource, for example.
    // A MediaType whose source is a more specific subclass never matches its
    // parent class's schema: VideoFile and AudioFile both extend File, so
    // video and audio MediaTypes must not match the document shape.
    // @see \Drupal\media\Plugin\media\Source\Image
    $media_types = array_filter(
      MediaType::loadMultiple(),
      fn (MediaTypeInterface $type): bool => is_a($type->getSource(), $media_source_class)
        && !self::hasMoreSpecificMediaSourceMapping($type, $media_source_class)
    );
    ksort($media_types);
    $media_type_ids = \array_map(
    // @phpstan-ignore-next-line
      fn (MediaTypeInterface $type): string => $type->id(),
      $media_types
    );
    return array_combine($media_type_ids, $media_types);
  }

  /**
   * Whether a MediaType's source is a more specific subclass of a source.
   *
   * A source class can be more specific because it maps to another schema
   * (VideoFile) or because it matches no schema at all (AudioFile). Either
   * way, its media types must not match the schema mapped to the given
   * (parent) source class.
   *
   * @param \Drupal\media\MediaTypeInterface $type
   *   A media type.
   * @param class-string $media_source_class
   *   The MediaSource plugin class being matched.
   *
   * @return bool
   */
  private static function hasMoreSpecificMediaSourceMapping(MediaTypeInterface $type, string $media_source_class): bool {
    $more_specific_source_classes = [
      ...\array_values(self::SCHEMA_TO_MEDIA_SOURCE),
      ...self::UNSUPPORTED_MEDIA_SOURCE_SUBCLASSES,
    ];
    foreach ($more_specific_source_classes as $other_source_class) {
      if ($other_source_class !== $media_source_class
        && is_subclass_of($other_source_class, $media_source_class)
        && is_a($type->getSource(), $other_source_class)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns FieldObjectProp for the given MediaSource.
   *
   * @param class-string $media_source_class
   *   A MediaSource plugin class.
   * @param \Drupal\canvas\TypedData\BetterEntityDataDefinition $media_entity_type_and_bundle
   * @param string $source_field_name
   *
   * @return non-empty-array<string, \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression|\Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression>
   */
  public static function getObjectProps(string $media_source_class, BetterEntityDataDefinition $media_entity_type_and_bundle, string $source_field_name): array {
    return match ($media_source_class) {
      Image::class => [
        // TRICKY: Additional computed property on image fields added by
        // Drupal Canvas.
        // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride
        // @phpcs:disable Drupal.Files.LineLength.TooLong
        'src' => new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'src_with_alternate_widths'),
        // @phpcs:enable
        'alt' => new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'alt'),
        'width' => new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'width'),
        'height' => new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'height'),
      ],
      VideoFile::class => [
        'src' => new ReferenceFieldPropExpression(
          new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'entity'),
          new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', \NULL, 'url')
        ),
      ],
      File::class => [
        'src' => new ReferenceFieldPropExpression(
          new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'entity'),
          new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'uri', \NULL, 'url')
        ),
        'filename' => new ReferenceFieldPropExpression(
          new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'entity'),
          new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'filename', \NULL, 'value')
        ),
        'filesize' => new ReferenceFieldPropExpression(
          new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'entity'),
          new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'filesize', \NULL, 'value')
        ),
        'mimetype' => new ReferenceFieldPropExpression(
          new FieldPropExpression($media_entity_type_and_bundle, $source_field_name, \NULL, 'entity'),
          new FieldPropExpression(BetterEntityDataDefinition::create('file'), 'filemime', \NULL, 'value')
        ),
      ],
      default => throw new \InvalidArgumentException(\sprintf('%s is not a supported Media Source class for shape matching.', $media_source_class)),
    };
  }

}
