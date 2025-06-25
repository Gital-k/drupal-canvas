<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\ComputedOembedResourceUrl;
use Drupal\media\Entity\MediaType;

/**
 * @todo Fix upstream.
 * @see \Drupal\experience_builder\Hook\ShapeMatchingHooks::entityBaseFieldInfoAlter()
 */
class OembedStringItemOverride extends StringItem {

  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['oembed_resource_url'] = DataDefinition::create('map')
      ->setLabel(t('Fully resolved oEmbed resource'))
      ->setComputed(TRUE)
      ->setReadOnly(TRUE)
      ->setClass(ComputedOembedResourceUrl::class);

    return $properties;
  }

  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    // Media using oembed:video source should generate URI as a sample.
    if ($field_definition->getTargetEntityTypeId() === 'media' && !empty($field_definition->getTargetBundle()) && MediaType::load($field_definition->getTargetBundle())->getSource()->getPluginId() === 'oembed:video') {
      return [
        'value' => 'https://vimeo.com/7073899',
      ];
    }

    return parent::generateSampleValue($field_definition);
  }

}
