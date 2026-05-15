<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\link\Plugin\Validation\Constraint\LinkExternalProtocolsConstraintValidator;

/**
 * Skips LinkExternalProtocols validation for LinkItems with no URI.
 */
final class CanvasLinkExternalProtocolsConstraintValidator extends LinkExternalProtocolsConstraintValidator {

  use CanvasSkipEmptyLinkItemTrait;

}
