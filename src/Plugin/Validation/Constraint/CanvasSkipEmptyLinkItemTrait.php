<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\link\LinkItemInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Short-circuits link constraint validators when the item has no URI.
 *
 * Core's link constraint validators (LinkType, LinkAccess,
 * LinkExternalProtocols, LinkNotExistingInternal) guard against a missing
 * LinkItem object via `isset($value)` but still call
 * `$link_item->getUrl()` on items whose `uri` property is NULL. That
 * forwards NULL into `Url::fromUri()`, triggering preg_match() /
 * parse_url() / str_starts_with() deprecations on PHP 8.1+ and - in the
 * case of LinkType - a spurious "The path '' is invalid." violation on
 * legitimately empty deltas.
 *
 * Canvas can hit that shape during programmatic form rebuild when a
 * newly added, invalid multi-value link delta is reverted to the stored
 * entity's non-existent original delta, leaving an auto-vivified empty
 * LinkItem on the in-memory entity. Those deprecations then surface to
 * the UI as preview toasts.
 *
 * Trait consumers must extend a core link constraint validator so that
 * `parent::validate()` is defined.
 *
 * @todo Remove once the upstream core fix lands.
 */
trait CanvasSkipEmptyLinkItemTrait {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint): void {
    if ($value instanceof LinkItemInterface && $value->isEmpty()) {
      // Nothing to validate; avoid routing a NULL URI into Url::fromUri().
      return;
    }
    parent::validate($value, $constraint);
  }

}
