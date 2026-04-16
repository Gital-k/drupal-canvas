<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\link\Plugin\Validation\Constraint\LinkTypeConstraintValidator;

/**
 * Skips LinkType validation for LinkItems with no URI to validate.
 */
final class CanvasLinkTypeConstraintValidator extends LinkTypeConstraintValidator {

  use CanvasSkipEmptyLinkItemTrait;

}
