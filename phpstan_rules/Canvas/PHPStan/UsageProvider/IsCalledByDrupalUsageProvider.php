<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use Drupal\canvas\Attribute\IsCalledByDrupal;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks members annotated with #[IsCalledByDrupal] as used.
 *
 * This is the generic counterpart to the pattern-specific usage providers in
 * this namespace: instead of detecting a category of Drupal-dispatched members
 * (a namespace, base class, interface …), it lets a single member opt out of
 * dead code detection in place, by documenting — via the attribute's `reason`
 * — how Drupal reaches it. It keeps one-off false positives from each requiring
 * their own bespoke usage provider.
 *
 * @see \Drupal\canvas\Attribute\IsCalledByDrupal
 */
final class IsCalledByDrupalUsageProvider extends ReflectionBasedMemberUsageProvider {

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    return $this->usageFromAttribute($method->getAttributes(IsCalledByDrupal::class));
  }

  protected function shouldMarkConstantAsUsed(\ReflectionClassConstant $constant): ?VirtualUsageData {
    return $this->usageFromAttribute($constant->getAttributes(IsCalledByDrupal::class));
  }

  protected function shouldMarkPropertyAsRead(\ReflectionProperty $property): ?VirtualUsageData {
    return $this->usageFromAttribute($property->getAttributes(IsCalledByDrupal::class));
  }

  protected function shouldMarkPropertyAsWritten(\ReflectionProperty $property): ?VirtualUsageData {
    return $this->usageFromAttribute($property->getAttributes(IsCalledByDrupal::class));
  }

  /**
   * @param list<\ReflectionAttribute<\Drupal\canvas\Attribute\IsCalledByDrupal>> $attributes
   */
  private function usageFromAttribute(array $attributes): ?VirtualUsageData {
    if ($attributes === []) {
      return NULL;
    }
    return VirtualUsageData::withNote(
      \sprintf('Called by Drupal (#[IsCalledByDrupal]): %s', $attributes[0]->newInstance()->reason),
    );
  }

}
