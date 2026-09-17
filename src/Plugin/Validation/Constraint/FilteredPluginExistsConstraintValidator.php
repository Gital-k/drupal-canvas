<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the FilteredPluginExists constraint.
 */
class FilteredPluginExistsConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $plugin_id, Constraint $constraint): void {
    if (!$constraint instanceof FilteredPluginExistsConstraint) {
      throw new UnexpectedTypeException($constraint, FilteredPluginExistsConstraint::class);
    }
    if ($plugin_id === NULL) {
      return;
    }
    if (!\is_string($plugin_id)) {
      throw new UnexpectedTypeException($plugin_id, 'string');
    }

    $filtered_definitions = $constraint->pluginManager->getFilteredDefinitions($constraint->consumer);
    if (!\array_key_exists($plugin_id, $filtered_definitions)) {
      $this->context->addViolation($constraint->unknownPluginMessage, [
        '@plugin_id' => $plugin_id,
        '@consumer' => $constraint->consumer,
      ]);
      return;
    }

    // If we don't need to validate the plugin class's interface, we're done.
    if (empty($constraint->interface)) {
      return;
    }

    if (!\is_a(DefaultFactory::getPluginClass($plugin_id, $filtered_definitions[$plugin_id]), $constraint->interface, TRUE)) {
      $this->context->addViolation($constraint->invalidInterfaceMessage, [
        '@plugin_id' => $plugin_id,
        '@interface' => $constraint->interface,
      ]);
    }
  }

}
