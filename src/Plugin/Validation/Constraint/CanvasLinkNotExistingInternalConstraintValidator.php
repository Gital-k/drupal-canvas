<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\link\Plugin\Validation\Constraint\LinkNotExistingInternalConstraintValidator;

/**
 * Skips LinkNotExistingInternal validation for LinkItems with no URI.
 */
final class CanvasLinkNotExistingInternalConstraintValidator extends LinkNotExistingInternalConstraintValidator {

  use CanvasSkipEmptyLinkItemTrait;

}
