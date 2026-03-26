<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use Drupal\Core\Controller\ControllerBase;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Disallows extending \Drupal\Core\Controller\ControllerBase.
 *
 * Drupal core's ControllerBase was meant toe ease the transition from Drupal 7.
 * At this point, there's many bad practices in there: it provides direct access
 * to the container (impairing testability), has many helper methods and
 * injected services that mask the actual dependencies of a controller.
 * Today, it is preferred to inject only the needed services.
 *
 * @see \Drupal\Core\Controller\ControllerBase
 *
 * In Canvas, we prefer all controllers relating to one domain to be co-located:
 * - auto-save functionality
 * - content entity-related functionality
 * - content entity-specific auto-save functionality
 * - config entity-related functionality
 *
 * @see \Drupal\canvas\Controller\ApiAutoSaveController
 * @see \Drupal\canvas\Controller\ApiContentControllers
 * @see \Drupal\canvas\Controller\ApiContentAutoSaveControllers
 * @see \Drupal\canvas\Controller\ApiConfigControllers
 *
 * @implements Rule<InClassNode>
 */
final class NoControllerBaseRule implements Rule {

  public function getNodeType(): string {
    return InClassNode::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    $classReflection = $node->getClassReflection();

    // Only check concrete classes (not interfaces or traits).
    if ($classReflection->isInterface() || $classReflection->isTrait()) {
      return [];
    }

    // Only flag if THIS class directly extends ControllerBase.
    // If a parent already extends it, that parent will be flagged instead.
    $parentClass = $classReflection->getParentClass();
    if ($parentClass === NULL) {
      return [];
    }

    if ($parentClass->getName() !== ControllerBase::class) {
      return [];
    }

    return [
      RuleErrorBuilder::message(
        \sprintf(
          'Class %s extends ControllerBase. Instead, register as a service and inject the needed dependencies.',
          $classReflection->getName(),
        )
      )
        ->identifier('canvas.noControllerBase')
        ->build(),
    ];
  }

}

