<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\link\Plugin\Validation\Constraint\LinkExternalProtocolsConstraint;

/**
 * Canvas shim for core's LinkExternalProtocols constraint.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasSkipEmptyLinkItemTrait
 */
final class CanvasLinkExternalProtocolsConstraint extends LinkExternalProtocolsConstraint {

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return CanvasLinkExternalProtocolsConstraintValidator::class;
  }

}
