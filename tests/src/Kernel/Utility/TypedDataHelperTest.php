<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Utility;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint;
use Drupal\canvas\Utility\TypedDataHelper;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[CoversClass(TypedDataHelper::class)]
#[Group('canvas')]
final class TypedDataHelperTest extends CanvasKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  /**
   * Primitive field-item properties are cast to their canonical PHP type.
   */
  public function testCastRawPhpTypesPrimitives(): void {
    $boolean = TypedDataHelper::conjureFieldItemObject('boolean');
    $boolean->setValue(['value' => '1']);
    // The stored string `'1'` becomes a real boolean TRUE.
    self::assertSame(['value' => TRUE], TypedDataHelper::castRawPhpTypes($boolean));

    $integer = TypedDataHelper::conjureFieldItemObject('integer');
    $integer->setValue(['value' => '5']);
    // The stored string `'5'` becomes a real integer 5.
    self::assertSame(['value' => 5], TypedDataHelper::castRawPhpTypes($integer));
  }

  /**
   * Empty items collapse to []; NULL properties are retained on non-empty items.
   *
   * Iterating a field item instantiates every non-computed property, even ones
   * that hold no value (their `getValue()` is NULL). Those must be dropped so
   * the normalized value is deterministic and safe to reconstruct.
   *
   * Whether an empty string survives depends on `ComplexDataInterface::isEmpty()`
   * for the item type:
   * - `text_long` (and other `StringItemBase`/`TextItemBase` subtypes) treat
   *   `value === ''` as empty, so the item collapses to `[]`.
   * - `language` uses the default `Map::isEmpty()`, which checks
   *   `getValue() !== NULL`; `''` satisfies that, so the item is not empty and
   *   `['value' => '']` is kept.
   */
  public function testCastRawPhpTypesCollapsesEmptyItems(): void {
    // Traversable present-but-empty complex values collapse to [].
    $string = TypedDataHelper::conjureFieldItemObject('string');
    $string->setValue(['value' => '']);
    self::assertSame([], TypedDataHelper::castRawPhpTypes($string));

    $string = TypedDataHelper::conjureFieldItemObject('string_long');
    $string->setValue(['value' => NULL]);
    self::assertSame([], TypedDataHelper::castRawPhpTypes($string));

    $string = TypedDataHelper::conjureFieldItemObject('text');
    $string->setValue(['value' => NULL]);
    self::assertSame([], TypedDataHelper::castRawPhpTypes($string));

    $string = TypedDataHelper::conjureFieldItemObject('list_string');
    $string->setValue(['value' => NULL]);
    self::assertSame([], TypedDataHelper::castRawPhpTypes($string));

    // A `text_long` item has non-computed `value` and `format` properties (plus
    // a computed `processed`). When only `value` carries a value, the NULL
    // `format` property is retained after normalization.
    $text = TypedDataHelper::conjureFieldItemObject('text_long');
    $text->setValue(['value' => 'hello']);
    self::assertSame(['value' => 'hello', 'format' => NULL], $text->getValue());
    self::assertSame(['value' => 'hello', 'format' => NULL], TypedDataHelper::castRawPhpTypes($text));

    // An empty-string value makes the item empty per TextItemBase::isEmpty(),
    // so the item collapses to [] rather than carrying ['value' => ''].
    $empty_text = TypedDataHelper::conjureFieldItemObject('text_long');
    $empty_text->setValue(['value' => '']);
    self::assertSame([], TypedDataHelper::castRawPhpTypes($empty_text));

    // A `language` item uses Map::isEmpty(), which returns FALSE when any
    // non-computed property has getValue() !== NULL. An empty string satisfies
    // that condition, so ['value' => ''] is kept rather than collapsed to [].
    $empty_lang = TypedDataHelper::conjureFieldItemObject('language');
    $empty_lang->setValue(['value' => '']);
    self::assertSame(['value' => ''], TypedDataHelper::castRawPhpTypes($empty_lang));
  }

  /**
   * Nullable `ComponentTreeItem` properties survive normalization as NULL.
   *
   * `parent_uuid`, `slot`, and `label` use `string` type with
   * `NotBlank(allowNull: TRUE)`. NULL must not be cast to `''` or `[]`.
   *
   * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::propertyDefinitions()
   * @see \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator::validate()
   * @see \Drupal\canvas\Utility\TypedDataHelper::castRawPhpTypes()
   */
  #[TestWith(['parent_uuid'])]
  #[TestWith(['slot'])]
  #[TestWith(['label'])]
  public function testCastRawPhpTypesRetainsNullableComponentTreeProperties(string $nullable_property): void {
    $this->enableModules(['canvas_test_sdc']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $storage = \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID);
    self::assertInstanceOf(EntityStorageInterface::class, $storage);

    $page = Page::create([
      'title' => 'Test page',
      'components' => [
        [
          'uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'd34b93534777207a',
          'inputs' => [
            'heading' => 'world',
          ],
          $nullable_property => NULL,
        ],
      ],
    ]);
    self::assertEntityIsValid($page);

    $normalized = TypedDataHelper::castRawPhpTypes($page->getComponentTree());
    self::assertIsArray($normalized);
    self::assertNotEmpty($normalized);

    self::assertArrayHasKey($nullable_property, $normalized[0], "::castRawPhpTypes() must retain the '$nullable_property' key.");
    self::assertNull($normalized[0][$nullable_property]);

    $reconstructed_page = $storage->create(['title' => [['value' => 'Test page']], 'components' => $normalized]);
    self::assertInstanceOf(Page::class, $reconstructed_page);
    self::assertEntityIsValid($reconstructed_page);

    $reconstructed_component_tree_item = $reconstructed_page->getComponentTree()->first();
    self::assertInstanceOf(ComponentTreeItem::class, $reconstructed_component_tree_item);

    match ($nullable_property) {
      'parent_uuid' => $value = $reconstructed_component_tree_item->getParentUuid(),
      'slot' => $value = $reconstructed_component_tree_item->getSlot(),
      'label' => $value = $reconstructed_component_tree_item->getLabel(),
      default => throw new \LogicException("Unhandled nullable property: $nullable_property"),
    };
    self::assertNull($value, "'$nullable_property' must be NULL after round-trip normalization.");
  }

  /**
   * Values are cast to the primitive types their config schema declares.
   *
   * @see \Drupal\Core\Config\StorableConfigBase::castValue()
   */
  public function testCastToConfigSchemaTypes(): void {
    $value = [
      'an_integer' => '42',
      // The Form API submits '' for an empty numeric field; that means "no
      // value", not zero.
      'a_nullable_integer' => '',
      'a_boolean' => '0',
      'a_string' => 5,
      'a_nested_mapping' => ['a_float' => '1.5'],
      // Config schema does not describe this key, so there is no type to cast
      // to and the value must survive verbatim.
      'not_in_the_schema' => '7',
    ];
    self::assertSame([
      'an_integer' => 42,
      'a_nullable_integer' => NULL,
      'a_boolean' => FALSE,
      'a_string' => '5',
      'a_nested_mapping' => ['a_float' => 1.5],
      'not_in_the_schema' => '7',
    ], TypedDataHelper::castToConfigSchemaTypes(self::conjureTypedConfig($value)));
  }

  /**
   * A value the config schema cannot represent is left exactly as it was.
   *
   * Casting it would silently replace it — `(int) 'not a number'` is `0` — and
   * Canvas keeps such a value on purpose, so that validation can report it and
   * the component instance form can show the Content Creator what they typed.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::clientModelToInput()
   */
  public function testCastToConfigSchemaTypesLeavesMismatchedValues(): void {
    $value = [
      'an_integer' => 'not a number',
      // Not an integer either: casting would silently round it down to 3.
      'a_nullable_integer' => '3.5',
      'a_nested_mapping' => ['a_float' => 'not a number'],
    ];
    self::assertSame($value, TypedDataHelper::castToConfigSchemaTypes(self::conjureTypedConfig($value)));
  }

  /**
   * Builds an ephemeral `type: mapping` config schema element around $value.
   *
   * Uses config schema rather than a real config object, so that the casting
   * of each primitive type can be asserted without depending on any particular
   * module's configuration.
   *
   * @param array $value
   *   The config values to wrap.
   *
   * @return \Drupal\Core\TypedData\TypedDataInterface
   *   Typed config for $value.
   */
  private static function conjureTypedConfig(array $value): TypedDataInterface {
    $typed_config_manager = \Drupal::service(TypedConfigManagerInterface::class);
    \assert($typed_config_manager instanceof TypedConfigManagerInterface);
    return $typed_config_manager->create(
      $typed_config_manager->buildDataDefinition([
        'type' => 'mapping',
        'mapping' => [
          'an_integer' => ['type' => 'integer'],
          'a_nullable_integer' => ['type' => 'integer', 'nullable' => TRUE],
          'a_boolean' => ['type' => 'boolean'],
          'a_string' => ['type' => 'string'],
          'a_nested_mapping' => [
            'type' => 'mapping',
            'mapping' => ['a_float' => ['type' => 'float']],
          ],
        ],
      ], $value),
      $value,
    );
  }

  public function testConjureFieldItemObject(): void {
    $item = TypedDataHelper::conjureFieldItemObject('string');
    self::assertInstanceOf(StringItem::class, $item);
    self::assertSame('field_item:string', $item->getDataDefinition()->getDataType());
  }

  /**
   * Reports only a genuine `->setInternal(TRUE)` as explicitly internal.
   */
  public function testIsExplicitlyInternal(): void {
    self::assertFalse(TypedDataHelper::isExplicitlyInternal(DataDefinition::create('string')));
    self::assertTrue(TypedDataHelper::isExplicitlyInternal(DataDefinition::create('string')->setInternal(TRUE)));
    // A computed definition reports `isInternal() === TRUE` by default, but that
    // is not an *explicit* internal mark.
    self::assertFalse(TypedDataHelper::isExplicitlyInternal(DataDefinition::create('string')->setComputed(TRUE)));
  }

  /**
   * Distinguishes genuine internal from a merely computed property.
   */
  public function testIsEffectivelyInternal(): void {
    // Non-computed and explicitly internal: genuinely internal.
    self::assertTrue(TypedDataHelper::isEffectivelyInternal(DataDefinition::create('string')->setInternal(TRUE)));
    // Computed only (internal is just the computed default): not genuine.
    self::assertFalse(TypedDataHelper::isEffectivelyInternal(DataDefinition::create('string')->setComputed(TRUE)));
    // Computed AND explicitly internal: genuinely internal.
    self::assertTrue(TypedDataHelper::isEffectivelyInternal(DataDefinition::create('string')->setComputed(TRUE)->setInternal(TRUE)));
    // Neither internal nor computed.
    self::assertFalse(TypedDataHelper::isEffectivelyInternal(DataDefinition::create('string')));
  }

  /**
   * Reports restricted only for an http/https-only URI constraint.
   */
  public function testIsRestrictedToHttpSchemes(): void {
    $http_https = DataDefinition::create('uri')
      ->addConstraint(UriSchemeConstraint::PLUGIN_ID, ['allowedSchemes' => ['http', 'https']]);
    self::assertTrue(TypedDataHelper::isRestrictedToHttpSchemes($http_https));

    // A subset of http/https still guarantees a browser URL.
    $https_only = DataDefinition::create('uri')
      ->addConstraint(UriSchemeConstraint::PLUGIN_ID, ['allowedSchemes' => ['https']]);
    self::assertTrue(TypedDataHelper::isRestrictedToHttpSchemes($https_only));

    // A scheme outside http/https means the value is not guaranteed usable.
    $mixed = DataDefinition::create('uri')
      ->addConstraint(UriSchemeConstraint::PLUGIN_ID, ['allowedSchemes' => ['http', 'public']]);
    self::assertFalse(TypedDataHelper::isRestrictedToHttpSchemes($mixed));

    // No constraint at all.
    self::assertFalse(TypedDataHelper::isRestrictedToHttpSchemes(DataDefinition::create('uri')));
  }

  /**
   * Cache tag is derived from the stored target id and entity type.
   */
  public function testGetDeletedReferencedEntityCacheability(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig('node');
    $this->createContentType(['type' => 'article']);

    $user = User::create(['name' => 'author']);
    self::assertSame(SAVED_NEW, $user->save());
    $node = Node::create(['type' => 'article', 'title' => 'Test', 'uid' => $user->id()]);
    self::assertSame(SAVED_NEW, $node->save());

    $item = $node->get('uid')->first();
    self::assertNotNull($item);
    $reference = $item->get('entity');
    self::assertInstanceOf(EntityReference::class, $reference);

    $cacheability = TypedDataHelper::getDeletedReferencedEntityCacheability($reference);
    self::assertSame(['user:' . $user->id()], $cacheability->getCacheTags());
  }

}
