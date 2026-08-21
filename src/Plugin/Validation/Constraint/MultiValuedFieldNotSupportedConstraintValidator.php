<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the MultiValuedFieldNotSupported constraint.
 *
 * @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3589536
 */
final class MultiValuedFieldNotSupportedConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof MultiValuedFieldNotSupportedConstraint) {
      throw new UnexpectedTypeException($constraint, MultiValuedFieldNotSupportedConstraint::class);
    }

    if ($value === NULL || !\is_string($value)) {
      return;
    }

    try {
      $parsed = StructuredDataPropExpression::fromString($value);
    }
    catch (\Throwable) {
      // Invalid expressions are handled by ValidStructuredDataPropExpression.
      return;
    }

    if (!$parsed instanceof EntityFieldBasedPropExpressionInterface) {
      return;
    }

    $multi_valued = $this->findMultiValuedField($parsed);
    if ($multi_valued !== NULL) {
      $this->context->addViolation($constraint->message, [
        '@field' => \sprintf(
          '%s.%s',
          $multi_valued->getHostEntityDataDefinition()->getDataType(),
          $multi_valued->getFieldName(),
        ),
      ]);
    }
  }

  /**
   * Finds the first use of a multi-valued field in an expression.
   *
   * Walks reference chains and object-prop expressions. Both scalar leaves on
   * a multi-valued field and *descending through* a multi-valued reference
   * field are found, regardless of delta: a delta-less expression resolves to
   * an unsupported delta-keyed array, and a delta-specific one cannot be
   * composed by the picker.
   *
   * @see \Drupal\canvas\Controller\ApiUiContentEntityReferenceControllers::listFields()
   */
  private function findMultiValuedField(EntityFieldBasedPropExpressionInterface $expression): ?FieldPropExpression {
    // Object-prop expression: sub-properties may contain reference
    // expressions (heterogeneous object props).
    if ($expression instanceof ObjectPropExpressionInterface) {
      foreach ($expression->getObjectExpressions() as $sub_expression) {
        // PHPStan fails to conclude the sub-expression already is an
        // EntityFieldBasedPropExpressionInterface (same as in
        // EntityFieldExpressionMustNotTargetInternalPropertyConstraintValidator).
        // @phpstan-ignore argument.type
        $found = $this->findMultiValuedField($sub_expression);
        if ($found !== NULL) {
          return $found;
        }
      }
      return NULL;
    }

    if ($expression instanceof ReferenceFieldPropExpression) {
      $found = $this->findMultiValuedField($expression->referencer);
      if ($found !== NULL) {
        return $found;
      }
      // A multi-target-bundle reference has one branch per bundle; descend into
      // every branch, since a multi-valued field in any branch is a violation.
      if ($expression->targetsMultipleBundles()) {
        \assert($expression->referenced instanceof ReferencedBundleSpecificBranches);
        foreach ($expression->referenced->bundleSpecificReferencedExpressions as $branch_expression) {
          $found = $this->findMultiValuedField($branch_expression);
          if ($found !== NULL) {
            return $found;
          }
        }
        return NULL;
      }
      $referenced = $expression->referenced;
      \assert($referenced instanceof EntityFieldBasedPropExpressionInterface);
      return $this->findMultiValuedField($referenced);
    }

    if ($expression instanceof FieldPropExpression) {
      return self::isMultiValued($expression) ? $expression : NULL;
    }

    return NULL;
  }

  /**
   * Whether an expression's field has a cardinality other than 1.
   */
  private static function isMultiValued(FieldPropExpression $expression): bool {
    $field_definition = $expression->getFieldDefinition();
    if ($field_definition === NULL) {
      // A nonexistent entity type or field is handled by other constraints.
      return FALSE;
    }
    return $field_definition->getFieldStorageDefinition()->getCardinality() !== 1;
  }

}
