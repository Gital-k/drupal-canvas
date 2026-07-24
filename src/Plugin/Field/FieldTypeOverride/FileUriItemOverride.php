<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

use Drupal\canvas\Plugin\DataTypeOverride\ComputedFileUrlOverride;
use Drupal\canvas\Plugin\DataTypeOverride\UriOverride;
use Drupal\canvas\Plugin\Validation\Constraint\UriConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\file\Plugin\Field\FieldType\FileUriItem;

/**
 * @todo Fix upstream.
 */
class FileUriItemOverride extends FileUriItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['value']
      ->setClass(UriOverride::class)
      // The `file_uri` field type stores a URI that uses a stream wrapper URI.
      ->addConstraint(UriConstraint::PLUGIN_ID, ['allowReferences' => FALSE]);
    $properties['url']
      ->setClass(ComputedFileUrlOverride::class)
      // The `url` property is computed using the `value` property, which is
      // required. Hence this value is guaranteed to exist.
      ->setRequired(TRUE)
      // The ComputedFileUrl data type generates a browser-accessible URL (root-
      // relative, absolute using HTTP, absolute using HTTPs or relative).
      ->addConstraint(UriConstraint::PLUGIN_ID, ['allowReferences' => TRUE])
      ->addConstraint(UriSchemeConstraint::PLUGIN_ID, [
        'allowedSchemes' => ['http', 'https'],
      ]);
    return $properties;
  }

}
