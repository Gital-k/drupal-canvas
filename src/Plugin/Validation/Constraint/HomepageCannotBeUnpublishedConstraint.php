<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validation constraint preventing homepage from being unpublished.
 */
#[Constraint(
  id: 'HomepageCannotBeUnpublished',
  label: new TranslatableMarkup('Homepage cannot be unpublished', [], ['context' => 'Validation']),
  type: ['entity']
)]
class HomepageCannotBeUnpublishedConstraint extends SymfonyConstraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public string $message = 'Cannot unpublish the homepage. Please set a different page as the homepage first.';

}
