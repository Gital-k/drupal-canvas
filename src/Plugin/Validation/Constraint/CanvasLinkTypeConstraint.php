<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\link\Plugin\Validation\Constraint\LinkTypeConstraint;

/**
 * Canvas shim for core's LinkType constraint.
 *
 * Routes validation through CanvasLinkTypeConstraintValidator so empty
 * LinkItems are skipped instead of triggering Url::fromUri(NULL)
 * deprecations (and a spurious "The path '' is invalid." violation).
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasSkipEmptyLinkItemTrait
 */
final class CanvasLinkTypeConstraint extends LinkTypeConstraint {

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return CanvasLinkTypeConstraintValidator::class;
  }

}
