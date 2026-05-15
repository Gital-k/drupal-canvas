<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\link\Plugin\Validation\Constraint\LinkAccessConstraintValidator;

/**
 * Skips LinkAccess validation for LinkItems with no URI to validate.
 */
final class CanvasLinkAccessConstraintValidator extends LinkAccessConstraintValidator {

  use CanvasSkipEmptyLinkItemTrait;

}
