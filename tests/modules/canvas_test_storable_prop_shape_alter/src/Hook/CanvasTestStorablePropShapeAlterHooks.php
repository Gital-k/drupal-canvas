<?php

declare(strict_types=1);

namespace Drupal\canvas_test_storable_prop_shape_alter\Hook;

use Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropShape\CandidateStorablePropShape;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\canvas_test_storable_prop_shape_alter\Plugin\Field\FieldType\StructuredPairItem;
use Drupal\Core\State\StateInterface;

class CanvasTestStorablePropShapeAlterHooks {

  public const STATE_KEY_AND_CACHE_TAG = 'canvas_storable_prop_shape_alter.prevent_dozens';

  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * Implements hook_canvas_storable_prop_shape_alter().
   */
  #[Hook('canvas_storable_prop_shape_alter')]
  public function storablePropShapeAlter(CandidateStorablePropShape $storable_prop_shape): void {
    // TRICKY: the `uri` field type (and data type) only support absolute URLs,
    // so this MUST NOT be used for `type: string, format: uri-reference`.
    // @see \Drupal\Tests\Core\Validation\Plugin\Validation\Constraint\PrimitiveTypeConstraintValidatorTest::testValidate()
    if ($storable_prop_shape->shape->schema == [
      'type' => 'string',
      'format' => 'uri',
    ]) {
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\UriItem::propertyDefinitions()
      // @phpstan-ignore-next-line
      $storable_prop_shape->fieldTypeProp = StructuredDataPropExpression::fromString('ℹ︎uri␟value');
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\UriItem::defaultFieldSettings()
      $storable_prop_shape->fieldInstanceSettings = NULL;
      // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget
      $storable_prop_shape->fieldWidget = 'uri';
    }

    // Show that a contrib module can add support for a new prop shape, even
    // using a new field type.
    // That can be dynamic based on some other state, so it would need to add
    // the required cache tags in that case.
    $storable_prop_shape->addCacheTags([self::STATE_KEY_AND_CACHE_TAG]);
    if ($this->state->get(self::STATE_KEY_AND_CACHE_TAG, FALSE)) {
      return;
    }
    if ($storable_prop_shape->shape->schema == ['type' => 'integer', 'multipleOf' => 12]) {
      // Use an imaginary `multiple_of` field type, provided by this module.
      // @phpstan-ignore-next-line
      $storable_prop_shape->fieldTypeProp = StructuredDataPropExpression::fromString('ℹ︎multiple_of␟value');
      $storable_prop_shape->fieldStorageSettings = ['must_be_divisible_by' => 12];
      $storable_prop_shape->fieldWidget = 'number';
    }

    // Show that a contrib module can declare a field type to fit an *object*
    // prop shape, including one with a nested `type: object` member.
    // Neither member carries validation constraints that scalar-driven shape
    // matching could recognize: this mapping is the only thing that makes the
    // field type fit. Contrib does exactly this — e.g. ui_icons for its
    // `{pack_id, icon_id, settings}` icon shape.
    // @see \Drupal\canvas_test_storable_prop_shape_alter\Plugin\Field\FieldType\StructuredPairItem
    $schema = $storable_prop_shape->shape->resolvedSchema;
    if (($schema['type'] ?? NULL) !== 'object') {
      return;
    }
    $properties = $schema['properties'] ?? [];
    if (($properties['first']['type'] ?? NULL) !== 'string' || ($properties['second']['type'] ?? NULL) !== 'string') {
      return;
    }
    $object_props = [
      'first' => new FieldTypePropExpression(StructuredPairItem::PLUGIN_ID, 'first'),
      'second' => new FieldTypePropExpression(StructuredPairItem::PLUGIN_ID, 'second'),
    ];
    // Only map the nested object member for shapes that declare it.
    if (($properties['extra']['type'] ?? NULL) === 'object') {
      $object_props['extra'] = new FieldTypePropExpression(StructuredPairItem::PLUGIN_ID, 'extra');
    }
    $storable_prop_shape->fieldTypeProp = new FieldTypeObjectPropsExpression(StructuredPairItem::PLUGIN_ID, $object_props);
    $storable_prop_shape->fieldWidget = 'string_textfield';
  }

  /**
   * Implements hook_field_widget_info_alter().
   */
  #[Hook('field_widget_info_alter')]
  public static function fieldWidgetInfoAlter(array &$info): void {
    $info['uri']['canvas'] = ['transforms' => ['mainProperty' => []]];
  }

}
