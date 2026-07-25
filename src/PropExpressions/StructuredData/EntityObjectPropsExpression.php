<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * An object of props reading multiple fields of ONE entity type + bundle.
 *
 * Unlike FieldObjectPropsExpression — whose every property is a sub-property of
 * a single field item — this reads across several fields of the same entity:
 * one property per field. It exists to be a bundle-specific branch value: a
 * multi-target-bundle content-entity-reference where a bundle reads more than
 * one field cannot be a single FieldObjectPropsExpression branch (a branch
 * member reads one field), so each bundle's picks become one of these objects,
 * gathered in a ReferencedBundleSpecificBranches. Each bundle's branch is
 * selected and bundle-matched at render, so no property is ever evaluated
 * against an entity of a different bundle.
 *
 * String representation drops the single-field header: it goes straight from
 * the entity type to the object body, and each property carries its own field.
 * @code
 * ℹ︎␜entity:media:image␟{field_media_image↠field_media_image␞␟alt,label↠name␞␟value}
 * @endcode
 *
 * Properties are scalars (`↠`) or references (`↝`); nested objects are not
 * supported (a bundle reading multiple properties of ONE field is handled by
 * the un-coalesced object form for now).
 *
 * @see \Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression
 * @see \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches
 * @see \Drupal\canvas\PropExpressions\StructuredData\Coalescer::coalesceReferencerFieldGroup()
 * @internal
 */
final class EntityObjectPropsExpression implements EntityFieldBasedPropExpressionInterface, ObjectPropExpressionInterface {

  use CompoundExpressionTrait;

  /**
   * @param non-empty-array<string, (ScalarPropExpressionInterface&EntityFieldBasedPropExpressionInterface)|(ReferencePropExpressionInterface&EntityFieldBasedPropExpressionInterface)> $objectPropsToFieldProps
   *   A mapping of prop names to entity field-based expressions that read a
   *   single field of this entity (a scalar leaf or a reference), each on its
   *   own field.
   */
  public function __construct(
    public readonly EntityDataDefinitionInterface $entityType,
    public readonly array $objectPropsToFieldProps,
  ) {
    \assert($this->objectPropsToFieldProps !== [], 'An entity-rooted object must have at least one property.');
    \assert(Inspector::assertAllStrings(\array_keys($this->objectPropsToFieldProps)));
    $expected_data_type = $this->entityType->getDataType();
    array_walk($objectPropsToFieldProps, function (mixed $expr) use ($expected_data_type): void {
      if (!$expr instanceof FieldPropExpression && !$expr instanceof ReferenceFieldPropExpression) {
        throw new \InvalidArgumentException(\sprintf('`%s` is not a valid entity-rooted object property: only a field or reference expression is allowed.', $expr instanceof \Stringable ? (string) $expr : \get_debug_type($expr)));
      }
      $prop_data_type = $expr->getHostEntityDataDefinition()->getDataType();
      if ($prop_data_type !== $expected_data_type) {
        throw new \InvalidArgumentException(\sprintf('`%s` reads from `%s`, but this object is rooted at `%s`; every property must read from the same entity type and bundle.', (string) $expr, $prop_data_type, $expected_data_type));
      }
    });
  }

  public function __toString(): string {
    return static::PREFIX_EXPRESSION_TYPE
      . static::PREFIX_ENTITY_LEVEL . $this->entityType->getDataType()
      . static::PREFIX_PROPERTY_LEVEL . static::PREFIX_OBJECT
      . implode(',', \array_map(
        function (
          string $obj_prop_name,
          (ScalarPropExpressionInterface&EntityFieldBasedPropExpressionInterface)|(ReferencePropExpressionInterface&EntityFieldBasedPropExpressionInterface) $expr,
        ): string {
          $referencer = $expr instanceof ReferenceFieldPropExpression ? $expr->referencer : $expr;
          // Each property carries its own field header (field ␞ delta ␟), so
          // the entity-rooted object needs none of its own.
          $field_header = $referencer->getFieldName()
            . static::PREFIX_FIELD_ITEM_LEVEL . ($referencer->getDelta() ?? '')
            . static::PREFIX_PROPERTY_LEVEL . $referencer->getFieldPropertyName();
          $tail = $expr instanceof ReferenceFieldPropExpression
            ? $field_header . static::PREFIX_ENTITY_LEVEL . (
              // A bundle-specific branch set already serializes without an
              // expression-type prefix; a plain referenced expression carries
              // one that must be stripped.
              $expr->referenced instanceof ReferencedBundleSpecificBranches
                ? (string) $expr->referenced
                : self::withoutExpressionTypePrefix((string) $expr->referenced)
            )
            : $field_header;
          return \sprintf(
            '%s%s%s',
            $obj_prop_name,
            $expr instanceof ReferenceFieldPropExpression
              ? self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE
              : self::SYMBOL_OBJECT_MAPPED_USE_PROP,
            $tail,
          );
        },
        \array_keys($this->objectPropsToFieldProps),
        \array_values($this->objectPropsToFieldProps),
      ))
      . static::SUFFIX_OBJECT;
  }

  public static function fromString(string $representation): static {
    // `ℹ︎␜<entity data type>␟{<entries>}` — no field header, so the first
    // property prefix separates the entity type from the object body.
    $body = self::withoutExpressionTypePrefix($representation);
    [$entity_part, $object_part] = explode(self::PREFIX_PROPERTY_LEVEL, $body, 2);
    $data_type = mb_substr($entity_part, mb_strlen(self::PREFIX_ENTITY_LEVEL));
    $entity_data_definition = BetterEntityDataDefinition::createFromDataType($data_type);
    // Strip the surrounding curly braces.
    $object_mapping = mb_substr($object_part, 1, -1);

    $props = [];
    foreach (self::splitObjectMapping($object_mapping) as $entry) {
      // The entry's own symbol is the FIRST one; deeper `↠`/`↝` belong to a
      // nested referenced expression.
      $use_prop_pos = mb_strpos($entry, self::SYMBOL_OBJECT_MAPPED_USE_PROP);
      $follow_reference_pos = mb_strpos($entry, self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE);
      $symbol = ($use_prop_pos !== FALSE && ($follow_reference_pos === FALSE || $use_prop_pos < $follow_reference_pos))
        ? self::SYMBOL_OBJECT_MAPPED_USE_PROP
        : self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE;
      [$obj_prop_name, $tail] = explode($symbol, $entry, 2);
      // Reattach the object's entity type and a field header to reconstitute a
      // stand-alone expression string, then parse it with the shared parser.
      $expr = StructuredDataPropExpression::fromString(
        self::PREFIX_EXPRESSION_TYPE . self::PREFIX_ENTITY_LEVEL . $data_type . self::PREFIX_FIELD_LEVEL . $tail
      );
      \assert($expr instanceof FieldPropExpression || $expr instanceof ReferenceFieldPropExpression);
      $props[$obj_prop_name] = $expr;
    }
    // splitObjectMapping() always returns at least one entry, so $props is
    // never empty.
    return new static($entity_data_definition, $props);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    \assert($host_entity === NULL || $host_entity instanceof FieldableEntityInterface);
    $dependencies = [];
    foreach ($this->objectPropsToFieldProps as $expr) {
      $dependencies = NestedArray::mergeDeep($dependencies, $expr->calculateDependencies($host_entity));
    }
    return $dependencies;
  }

  public function validateSupport(EntityInterface|FieldItemInterface|FieldItemListInterface $entity): void {
    \assert($entity instanceof EntityInterface);
    $expected_entity_type_id = $this->entityType->getEntityTypeId();
    if ($entity->getEntityTypeId() !== $expected_entity_type_id) {
      throw new \DomainException(\sprintf("`%s` is an expression for entity type `%s`, but the provided entity is of type `%s`.", (string) $this, $expected_entity_type_id, $entity->getEntityTypeId()));
    }
    $expected_bundles = $this->entityType->getBundles();
    \assert($expected_bundles === NULL || count($expected_bundles) === 1);
    if ($expected_bundles !== NULL && $entity->bundle() !== $expected_bundles[0]) {
      throw new \DomainException(\sprintf("`%s` is an expression for entity type `%s`, bundle `%s`, but the provided entity is of the bundle `%s`.", (string) $this, $expected_entity_type_id, $expected_bundles[0], $entity->bundle()));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getHostEntityDataDefinition(): EntityDataDefinitionInterface {
    return $this->entityType;
  }

  /**
   * {@inheritdoc}
   *
   * An entity-rooted object spans multiple fields, so it has no single field
   * name. It only ever appears as a bundle-specific branch value, where it is
   * identified by its host entity type + bundle, never by a field name.
   */
  public function getFieldName(): string {
    throw new \LogicException('An entity-rooted object spans multiple fields and has no single field name.');
  }

  /**
   * {@inheritdoc}
   */
  public function getDelta(): ?int {
    // An entity-rooted object always evaluates one entity to one object.
    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * @see ::getFieldName()
   */
  public function getStartingPointKey(): string {
    throw new \LogicException('An entity-rooted object spans multiple fields and has no single starting point.');
  }

  /**
   * {@inheritdoc}
   *
   * An entity-rooted object is named by the reference that descends into it,
   * not by a field of its own.
   *
   * @see \Drupal\canvas\PropExpressions\StructuredData\Coalescer::branchDeveloperFacingKey()
   */
  public function getDeveloperFacingKey(): string {
    throw new \LogicException('An entity-rooted object has no single developer-facing key; it is named by its referencer.');
  }

  /**
   * {@inheritdoc}
   *
   * @return non-empty-array<string, (\Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface&\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface)|(\Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface&\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface)>
   */
  public function getObjectExpressions(): array {
    return $this->objectPropsToFieldProps;
  }

}
