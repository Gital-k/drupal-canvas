<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * Validates that a value is the string form of a structured data expression.
 *
 * @see \Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression::fromString()
 */
#[Constraint(
  id: 'ValidStructuredDataPropExpression',
  label: new TranslatableMarkup('Validates a field prop expression', [], ['context' => 'Validation']),
)]
final class ValidStructuredDataPropExpressionConstraint extends Choice {}
