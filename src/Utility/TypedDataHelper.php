<?php

declare(strict_types=1);

namespace Drupal\canvas\Utility;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Schema\Ignore;
use Drupal\Core\Config\Schema\Undefined;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\Type\FloatInterface;
use Drupal\Core\TypedData\Type\IntegerInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\path\Plugin\Field\FieldType\PathItem;
use Drupal\text\Plugin\Field\FieldType\TextItemBase;

/**
 * @internal
 */
final readonly class TypedDataHelper {

  /**
   * Whether a field/property definition explicitly opts in to being internal.
   *
   * `DataDefinitionInterface::isInternal()` cannot distinguish an explicit
   * `->setInternal(TRUE)` from the value it defaults to for computed
   * properties when left unset — both return `TRUE`. That makes it
   * impossible, via the interface alone, to respect an explicit internal
   * mark on a computed property (e.g. `DateTimeItemOverride` marking `date`
   * internal): `isInternal() && !isComputed()` is always `FALSE` for it.
   * Inspecting the raw definition array is the only way to tell them apart.
   *
   * @see \Drupal\Core\TypedData\DataDefinition::isInternal()
   */
  public static function isExplicitlyInternal(DataDefinitionInterface $definition): bool {
    return $definition instanceof DataDefinition && ($definition->toArray()['internal'] ?? NULL) === TRUE;
  }

  /**
   * Whether a field/property definition is genuinely internal.
   *
   * Stricter than `DataDefinitionInterface::isInternal()`, which returns TRUE
   * for *every* computed property (see `DataDefinition::isInternal()`: it
   * falls back to `isComputed()` when no `internal` flag is set). That
   * conflates a genuinely internal property with a merely computed one. This
   * method reports internal only when the mark is genuine — the definition is
   * internal AND either:
   * - it is not computed (so `isInternal()` cannot be the computed default), or
   * - it explicitly opts in via `->setInternal(TRUE)`, i.e.
   *   ::isExplicitlyInternal() returns TRUE.
   *
   * Two computed properties, both with `isInternal() === TRUE`, show why
   * ::isExplicitlyInternal() alone is not sufficient:
   * - `date` on a `datetime` field: DateTimeItemOverride marks it via
   *   `->setInternal(TRUE)`, so it is genuinely internal — both methods agree.
   * - `processed` on a `text` field: internal *only* because it is computed
   *   (nobody marked it), so it is not genuinely internal — both methods
   *   return FALSE.
   * The methods diverge on a *non-computed* internal definition that is not a
   * `DataDefinition` instance (e.g. a `FieldConfig`, a config entity that
   * implements the interface): ::isExplicitlyInternal() returns FALSE there
   * because of its `instanceof` guard, whereas this method still reports it
   * internal via `isInternal() && !isComputed()`.
   *
   * @see ::isExplicitlyInternal()
   * @see \Drupal\Core\TypedData\DataDefinition::isInternal()
   */
  public static function isEffectivelyInternal(DataDefinitionInterface $definition): bool {
    return $definition->isInternal() && (!$definition->isComputed() || self::isExplicitlyInternal($definition));
  }

  /**
   * Whether a `uri`-typed property is guaranteed to resolve to a browser URL.
   *
   * A UriSchemeConstraint restricted to a subset of http/https guarantees the
   * value is an absolute HTTP(S) URL — the only kind that is directly usable
   * in a browser (e.g. in an `<img src>` or `<a href>`). Properties lacking
   * this — e.g. the raw `uri` on a `link` field (which can be
   * `entity:node/1`), or the raw `value` on a `file_uri` field (a
   * stream-wrapper URI like `public://image.jpg`) — are not.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\UriSchemeConstraint
   */
  public static function isRestrictedToHttpSchemes(DataDefinitionInterface $property_definition): bool {
    $constraint = $property_definition->getConstraint(UriSchemeConstraint::PLUGIN_ID);
    $allowed_schemes = $constraint['allowedSchemes'] ?? [];
    return $allowed_schemes !== [] && \array_diff($allowed_schemes, ['http', 'https']) === [];
  }

  public static function conjureFieldItemObject(string $field_type): FieldItemInterface {
    $typed_data_manager = self::getTypedDataManger();
    $field_item_definition = $typed_data_manager->createDataDefinition("field_item:$field_type");
    $field_item = $typed_data_manager->createInstance("field_item:$field_type", [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    \assert($field_item instanceof FieldItemInterface);
    return $field_item;
  }

  /**
   * Returns cacheability for a deleted referenced entity.
   *
   * When an entity reference field item's target has been deleted, the target
   * ID is still stored but the entity no longer loads. Any cached result that
   * depends on the (now-absent) entity must carry this cacheability so it is
   * invalidated if the entity is recreated at the same ID.
   *
   * TRICKY: imperfect; uses `entity_type_id:id` which is the default for most
   * entity types, but not all.
   *
   * @see \Drupal\Core\Entity\EntityBase::getCacheTagsToInvalidate()
   */
  public static function getDeletedReferencedEntityCacheability(EntityReference $reference): CacheableDependencyInterface {
    $target_id = $reference->getTargetIdentifier();
    $target_entity_type_id = $reference->getTargetDefinition()->getEntityTypeId();
    if ($target_id === NULL || $target_entity_type_id === NULL) {
      return new CacheableMetadata();
    }
    return (new CacheableMetadata())->addCacheTags([$target_entity_type_id . ':' . $target_id]);
  }

  /**
   * Normalizes typed data to canonical, comparable PHP values.
   *
   * Produces a deterministic representation intended for hashing and for
   * storing the data in an auto-save item:
   * - Primitives are cast to their canonical PHP type (e.g. a boolean stored
   *   as the string '1' becomes `TRUE`), so equivalent values compare equal.
   * - `ComponentTreeItem` inputs are optimized (on a clone, without side
   *   effects) so equivalent component trees produce identical output.
   * - Traversable elements are recursively cast; an all-NULL value stays NULL,
   *   and a present-but-empty complex value becomes [].
   *
   * @param \Drupal\Core\TypedData\TypedDataInterface $element
   *   The typed data to normalize.
   *
   * @return mixed
   *   Scalar/NULL for primitives; an array (keys preserved) for traversable
   *   elements.
   */
  public static function castRawPhpTypes(TypedDataInterface $element): mixed {
    // For component instances, always consider the data as it will be stored
    // in its optimized form, without causing side effects.
    if ($element instanceof ComponentTreeItem) {
      $element = clone $element;
      $element->optimizeInputs();
    }

    if ($element instanceof PrimitiveInterface) {
      return match (TRUE) {
        // Some properties of ComponentTreeItem use `'string'` data type in
        // combination with `NotBlank(allowNull: TRUE)` constraint. Casting them
        // via `getCastedValue()` results in an `''` if the property value is
        // `NULL`. We don't want that, as `NULL` is a valid value for these
        // properties and `''` is not.
        $element->getParent() instanceof ComponentTreeItem && \in_array($element->getName(), ComponentTreeItem::NULLABLE_STRING_PROPERTIES, TRUE),
        // The `langcode` property of PathItem field starts as `NULL` when its
        // fieldable entity is instantiated, so we retain `NULL` value.
        $element->getParent() instanceof PathItem && $element->getName() === 'langcode',
        // Entity reference property `target_uuid` is nullable , retain `NULL`.
        $element->getParent() instanceof EntityReferenceItem && $element->getName() === 'target_uuid',
        // The `format` property of text fields is nullable, retain `NULL`.
        $element->getParent() instanceof TextItemBase && $element->getName() === 'format' =>
          !\is_null($element->getValue()) ? $element->getCastedValue() : NULL,

        // Other primitives should be cast as per their ::getCastedValue().
        default => $element->getCastedValue(),
      };
    }

    if ($element instanceof TraversableTypedDataInterface) {
      // Preserve `toArray()` NULL-vs-array distinction: an optional, unset
      // sequence/mapping stays NULL rather than becoming an empty array, which
      // would needlessly change the hash of components that have such a key.
      if ($element->getValue() === NULL) {
        return NULL;
      }

      // A present-but-empty complex value is an empty array, not NULL: NULL is
      // reserved above for an absent value, and collapsing the two would change
      // the hash of items that exist but hold no data.
      if ($element instanceof ComplexDataInterface && $element->isEmpty()) {
        return [];
      }

      $children = \iterator_to_array($element);

      return \array_map(fn ($child) => self::castRawPhpTypes($child), $children);
    }
    // Anything that is neither a primitive nor traversable (e.g. an `ignore`
    // element) has no canonical config-schema type; use its value verbatim.
    return $element->getValue();
  }

  /**
   * Casts config values to the types their config schema declares.
   *
   * Configuration stored in a config object gets this for free:
   * \Drupal\Core\Config\Config::save() casts every value using config schema,
   * which is why a Block config entity ends up with `level: 1` even though the
   * Form API submitted the string `'1'`. Configuration that is not stored in a
   * config object — such as the block settings that Canvas stores in a
   * component instance's `inputs` — must be cast explicitly.
   *
   * Mirrors core, with one deliberate difference: a value whose primitive type
   * does not match the config schema is returned unchanged instead of cast.
   * Casting it would silently replace it (`(int) 'no such page'` is `0`), and
   * Canvas keeps such a value on purpose, so that validation can report it and
   * the component instance form can show the Content Creator what they typed.
   *
   * Unlike ::castRawPhpTypes(), which normalizes content typed data into a
   * hashable representation, this casts config typed data into the exact
   * representation config schema prescribes for storage.
   *
   * @param \Drupal\Core\TypedData\TypedDataInterface $element
   *   Typed config, typically from
   *   \Drupal\Core\Config\TypedConfigManagerInterface::createFromNameAndData().
   *
   * @return mixed
   *   Scalar or NULL for primitives; an array (keys preserved) for traversable
   *   elements.
   *
   * @see \Drupal\Core\Config\StorableConfigBase::castValue()
   * @see ::castRawPhpTypes()
   */
  public static function castToConfigSchemaTypes(TypedDataInterface $element): mixed {
    // Config schema declines to describe these, so there is no type to cast to.
    if ($element instanceof Undefined || $element instanceof Ignore) {
      return $element->getValue();
    }

    if ($element instanceof TraversableTypedDataInterface) {
      // An absent mapping or sequence must stay absent rather than become an
      // empty array.
      if ($element->getValue() === NULL) {
        return NULL;
      }
      return \array_map(
        static fn (TypedDataInterface $child): mixed => self::castToConfigSchemaTypes($child),
        \iterator_to_array($element),
      );
    }

    if (!$element instanceof PrimitiveInterface) {
      return $element->getValue();
    }

    $value = $element->getValue();
    // The config system is primarily fed by the Form API, where an empty string
    // for a numeric field means "no value" rather than the number zero.
    // @see \Drupal\Core\Config\StorableConfigBase::castValue()
    if ($value === NULL || ($value === '' && ($element instanceof IntegerInterface || $element instanceof FloatInterface))) {
      return NULL;
    }

    return self::isOfCorrectPrimitiveType($element) ? $element->getCastedValue() : $value;
  }

  /**
   * Whether a primitive's value is representable as its config schema type.
   *
   * Asks the very constraint that reports this mismatch during validation, so
   * that what is cast here and what validates there cannot drift apart.
   *
   * @param \Drupal\Core\TypedData\TypedDataInterface $element
   *   The primitive typed config element to check.
   *
   * @return bool
   *   TRUE if the value can be cast without being replaced by a different one.
   *
   * @see \Drupal\Core\Validation\Plugin\Validation\Constraint\PrimitiveTypeConstraintValidator
   */
  private static function isOfCorrectPrimitiveType(TypedDataInterface $element): bool {
    \assert($element instanceof PrimitiveInterface);
    // TRICKY: this hands the generic typed data manager's validator even config
    // typed data, which carries its own manager (TypedConfigManager). That is
    // correct: the validator re-resolves the element's own manager.
    // @see \Drupal\Core\TypedData\Validation\RecursiveContextualValidator::validateNode()
    $typed_data_manager = self::getTypedDataManger();
    $primitive_type_constraint = $typed_data_manager->getValidationConstraintManager()
      ->create('PrimitiveType', []);
    return $typed_data_manager->getValidator()
      ->validate($element, $primitive_type_constraint)
      ->count() === 0;
  }

  private static function getTypedDataManger(): TypedDataManagerInterface {
    return \Drupal::typedDataManager();
  }

}
