<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates an exposed slot', [], ['context' => 'Validation']),
)]
final class ValidExposedSlotConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'ValidExposedSlot';

  /**
   * The view mode in which the exposed slots will be used.
   *
   * @var string
   */
  public string $viewMode = 'full';

  /**
   * Violation message for an exposed slot on a component absent from the tree.
   */
  public string $unknownComponentMessage = 'The component %id does not exist in the tree.';

  /**
   * Violation message for exposing a slot that already contains components.
   */
  public string $slotNotEmptyMessage = 'The %slot slot must be empty.';

  /**
   * Violation message for a slot the component does not define.
   */
  public string $undefinedSlotMessage = 'The component %id does not have a %slot slot.';

  /**
   * Violation message for an exposed slot outside the `$viewMode` view mode.
   */
  public string $viewModeMismatchMessage = 'Exposed slots are only allowed in the %mode view mode.';

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): string {
    return 'viewMode';
  }

}
