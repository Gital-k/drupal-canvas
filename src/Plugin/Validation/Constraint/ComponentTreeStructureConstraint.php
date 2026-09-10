<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates a component tree structure.
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates the component tree structure', [], ['context' => 'Validation']),
)]
class ComponentTreeStructureConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'ComponentTreeStructure';

  /**
   * Property path that violations are reported relative to.
   *
   * Defaults to the empty string, in which case the validator falls back to the
   * property path of the value being validated.
   */
  public string $basePropertyPath = '';

}
