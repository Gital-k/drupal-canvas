<?php

declare(strict_types=1);

namespace Drupal\canvas\Attribute;

/**
 * Marks a member as reachable only through Drupal's dynamic dispatch.
 *
 * Drupal invokes many class members indirectly — hook implementations, route
 * controllers, access checkers, methods called via polymorphic dispatch from a
 * Drupal or vendor base class, and so on. The dead code detector
 * (`shipmonk/dead-code-detector`) cannot trace these call sites and would
 * otherwise report the member as unused.
 *
 * Annotate such a member with this attribute to record that Drupal reaches it.
 * For one-off cases this is a lighter-weight alternative to adding a bespoke
 * `Canvas\PHPStan\UsageProvider`: prefer a usage provider only when the same
 * reasoning applies to many members that share a detectable pattern (namespace,
 * base class, interface …).
 *
 * This attribute has no runtime behavior; it exists only to inform static
 * analysis.
 *
 * @see \Canvas\PHPStan\UsageProvider\IsCalledByDrupalUsageProvider
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS_CONSTANT | \Attribute::TARGET_PROPERTY)]
final class IsCalledByDrupal {

  /**
   * @param string $reason
   *   A short explanation of how Drupal reaches the annotated member.
   */
  public function __construct(
    public readonly string $reason,
  ) {}

}
