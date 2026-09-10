<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\InvalidComponentInputsPropSourceException;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Validates a single component instance in a component tree.
 *
 * Besides delegating to the component source plugin, this checks that every
 * explicit input is stored in its *collapsed* representation: the compact
 * storage representation that Canvas requires, as opposed to the *uncollapsed*
 * (runtime) representation.
 *
 * @see docs/data-model.md, section 3.2.2
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::collapse()
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::uncollapse()
 */
final class ValidComponentTreeItemConstraintValidator extends ConstraintValidator {

  use ConfigComponentTreeTrait;
  use ConstraintPropertyPathTranslatorTrait;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if ($value === NULL) {
      return;
    }

    if (!$value instanceof ComponentTreeItem && !\is_array($value)) {
      throw new \UnexpectedValueException(\sprintf('The value must be a ComponentTreeItem object or an array, found %s.', gettype($value)));
    }

    // Validate the raw structure:
    // - if this is a `canvas.component_tree`, that is the received value
    // - if this is a `field_item:component_tree`, that is the array
    //   representation of the field item object
    if (!$this->validateRawStructure(\is_array($value) ? $value : $value->toArray())) {
      // ::validateRawStructure()'s validation errors should be fixed first.
      return;
    }

    // Validate in-depth. This is simpler if the ComponentTreeItem-provided
    // infrastructure is available, so conjure one from $value if not already.
    $fieldable_host_entity = NULL;
    if (!$value instanceof ComponentTreeItem) {
      \assert(\array_key_exists('uuid', $value));
      \assert(\array_key_exists('component_id', $value));
      \assert(\array_key_exists('inputs', $value));
      $value = $this->conjureFieldItemObject($value);
    }
    else {
      $host_entity = $value->getParent()?->getParent();
      if ($host_entity !== NULL && !$host_entity instanceof ConfigEntityAdapter) {
        $fieldable_host_entity = $value->getEntity();
        \assert($fieldable_host_entity instanceof FieldableEntityInterface);
      }
    }

    // Validate the prop source resolves into a value that is considered
    // valid by the source plugin.
    $component_source = $value->getComponent()?->getComponentSource();
    if ($component_source === NULL) {
      // TRICKY: ignore missing Component config entities; that's the
      // responsibility of another validator.
      // @see \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator::validateComponentInstance()
      // @todo Refactor this away after https://www.drupal.org/project/drupal/issues/2820364 is fixed.
      return;
    }

    // Get the stored explicit input. Only add a violation error if the
    // Component in its current definition requires explicit input. (Silently
    // ignore stored inputs that are no longer required per Postel's law.)
    // @see https://en.wikipedia.org/wiki/Robustness_principle
    try {
      $stored_explicit_input = $value->get('inputs')->getValues();
    }
    catch (MissingComponentInputsException $e) {
      if ($component_source->requiresExplicitInput()) {
        $this->context->buildViolation('The required properties are missing.')
          ->atPath(\sprintf('inputs.%s', $e->componentInstanceUuid))
          ->addViolation();
        return;
      }
      else {
        // Fall back to empty input.
        $stored_explicit_input = [];
      }
    }

    \assert(\is_array($stored_explicit_input));
    $component_violations = $this->translateConstraintPropertyPathsAndRoot(
      ['' => $this->context->getPropertyPath() . '.'],
      $component_source->validateComponentInput(
        inputValues: $stored_explicit_input,
        component_instance_uuid: $value->getUuid(),
        entity: $fieldable_host_entity,
      ),
      // We need to ensure the validation root context is transferred over.
      $this->context->getRoot()
    );
    if ($component_violations->count() > 0) {
      // @todo Remove the foreach and use ::addAll once
      // https://www.drupal.org/project/drupal/issues/3490588 has been resolved.
      foreach ($component_violations as $violation) {
        $this->context->getViolations()->add($violation);
      }
    }

    // Also add a violation error if the inputs contain either:
    // - static prop sources that deviate from those for the Component entity at
    //   the referenced version
    // - prop sources of any type that are unoptimized ("uncollapsed")
    // Unless there's >=1 garbage input violation: optimization would fail in
    // that case, so there's no point in attempting it. The garbage value must
    // be removed first.
    \assert($component_violations instanceof ConstraintViolationList);
    if ($component_violations->findByCodes(ComponentTreeItem::VIOLATION_CODE_GARBAGE_INPUT)->count() > 0) {
      return;
    }
    try {
      $value->optimizeInputs();
    }
    catch (InvalidComponentInputsPropSourceException) {
      $this->context->buildViolation(
        'Using a static prop source that deviates from the configuration for Component %component_id at version %component_version.',
        [
          '%component_id' => $value->getComponentId(),
          '%component_version' => $value->getComponentVersion(),
        ])
        ->atPath(\sprintf('inputs.%s', $value->getUuid()))
        ->addViolation();
    }
    // Don't allow uncollapsed inputs: every prop source must be stored in its
    // collapsed representation. ::optimizeInputs() collapsed the inputs above,
    // so any input whose stored representation differs from the optimized one
    // was stored uncollapsed.
    // @see docs/data-model.md, section 3.2.2
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::collapse()
    $collapsed_explicit_input = $value->getInputs() ?? [];
    foreach ($stored_explicit_input as $prop_name => $stored_prop_input) {
      $collapsed_prop_input = $collapsed_explicit_input[$prop_name] ?? NULL;
      if ($stored_prop_input === $collapsed_prop_input) {
        continue;
      }
      // Only prop sources stored as an array carrying a `sourceType` key are
      // uncollapsed, and hence only those can be collapsed any further.
      \assert(\is_array($stored_prop_input) && \array_key_exists('sourceType', $stored_prop_input));
      $this->context->buildViolation(
        'The %prop_name component input uses the uncollapsed representation of the %source_type prop source. It must use the collapsed representation: %collapsed_representation.',
        [
          '%prop_name' => $prop_name,
          '%source_type' => $stored_prop_input['sourceType'],
          '%collapsed_representation' => \json_encode($collapsed_prop_input, \JSON_THROW_ON_ERROR),
        ])
        ->atPath(\sprintf('inputs.%s', $value->getUuid()))
        ->addViolation();
    }
  }

  /**
   * Validates that the two required key-value pairs are present.
   *
   * @param array{tree?: string, inputs?: string} $raw_component_tree_values
   *
   * @return bool
   *   TRUE when valid, FALSE when not. Indicates whether to validate further.
   */
  private function validateRawStructure(array $raw_component_tree_values): bool {
    $is_valid = TRUE;
    if (!\array_key_exists('uuid', $raw_component_tree_values)) {
      $this->context->addViolation('The array must contain a "uuid" key.');
      $is_valid = FALSE;
    }
    if (!\array_key_exists('component_id', $raw_component_tree_values)) {
      $this->context->addViolation('The array must contain a "component_id" key.');
      $is_valid = FALSE;
    }
    if (!\array_key_exists('inputs', $raw_component_tree_values)) {
      $this->context->addViolation('The array must contain an "inputs" key.');
      $is_valid = FALSE;
    }
    return $is_valid;
  }

}
