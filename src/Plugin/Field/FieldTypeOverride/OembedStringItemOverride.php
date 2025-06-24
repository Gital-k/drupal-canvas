<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\media\Entity\MediaType;

/**
 * @todo Fix upstream.
 * @see \Drupal\experience_builder\Hook\ShapeMatchingHooks::entityBaseFieldInfoAlter()
 */
class OembedStringItemOverride extends StringItem {

  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    // Media using oembed:video source should generate URI as a sample.
    if ($field_definition->getTargetEntityTypeId() === 'media' && !empty($field_definition->getTargetBundle()) && MediaType::load($field_definition->getTargetBundle())->getSource()->getPluginId() === 'oembed:video') {
      return UriItemOverride::generateSampleValue($field_definition);
    }

    return parent::generateSampleValue($field_definition);
  }

}
