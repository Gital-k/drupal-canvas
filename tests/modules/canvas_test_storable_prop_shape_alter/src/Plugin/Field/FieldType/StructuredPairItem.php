<?php

declare(strict_types=1);

namespace Drupal\canvas_test_storable_prop_shape_alter\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * A field type that only hook_canvas_storable_prop_shape_alter() can match.
 *
 * Deliberately declares no validation constraints on its string properties:
 * they hold machine names, not prose, so Canvas' scalar-driven shape matching
 * cannot (and should not) find them on its own. The only thing that says this
 * field type fits an object prop shape is the hook implementation.
 *
 * Modelled on contrib field types that hold structured identifiers — e.g.
 * ui_icons' `ui_icon`, which stores an icon pack ID plus an icon ID.
 *
 * @see \Drupal\canvas_test_storable_prop_shape_alter\Hook\CanvasTestStorablePropShapeAlterHooks::storablePropShapeAlter()
 */
#[FieldType(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Structured pair"),
  category: "general",
  default_widget: "string_textfield",
  default_formatter: "string",
)]
final class StructuredPairItem extends FieldItemBase {

  public const PLUGIN_ID = 'structured_pair';

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['first'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('First'))
      ->setRequired(TRUE);
    $properties['second'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Second'))
      ->setRequired(TRUE);
    // A non-scalar property, to match the nested `type: object` member of the
    // prop shape this field type is declared to fit.
    $properties['extra'] = MapDataDefinition::create()
      ->setLabel(new TranslatableMarkup('Extra'))
      ->setInternal(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'first' => ['type' => 'varchar', 'length' => 128],
        'second' => ['type' => 'varchar', 'length' => 128],
        'extra' => ['type' => 'blob', 'size' => 'big', 'serialize' => TRUE],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    return $this->get('first')->getValue() === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    return ['first' => 'alpha', 'second' => 'beta'];
  }

}
