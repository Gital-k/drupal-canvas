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
  type: ['string']
)]
class IsDisplayBuildableEntityTypeConstraint extends SymfonyConstraint {

  public string $notContentEntityTypeMessage = "'@entity_type_id' is not a content entity type.";

  public string $noFieldUiBaseRouteMessage = "The '@entity_type_id' entity type does not specify a 'field_ui_base_route'.";

}
