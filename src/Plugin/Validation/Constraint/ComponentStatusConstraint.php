<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates that a component instance does not use a disabled component.
 */
#[Constraint(
  id: 'ComponentStatusConstraint',
  label: new TranslatableMarkup('Validates the component status', [], ['context' => 'Validation']),
)]
final class ComponentStatusConstraint extends SymfonyConstraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public string $message = "The component '%component' cannot be enabled because it does not meet the requirements of Drupal Canvas.";

}
