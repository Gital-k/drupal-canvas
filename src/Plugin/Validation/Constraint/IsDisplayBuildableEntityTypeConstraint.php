<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * @internal
 */
#[Constraint(
  id: 'IsDisplayBuildableEntityType',
  label: new TranslatableMarkup('Is display-buildable entity type', [], ['context' => 'Validation']),
  type: ['string'],
)]
class IsDisplayBuildableEntityTypeConstraint extends SymfonyConstraint {

  public string $notContentEntityTypeMessage = "This is not a content entity type.";

  public string $noFieldUiBaseRouteMessage = "This entity type does not support Field UI, so there is no way to configure its display.";

}
