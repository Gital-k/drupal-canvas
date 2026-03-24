<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Disallows extending \Drupal\Core\Controller\ControllerBase.
 *
 * ControllerBase accesses services via the global container (static calls to
 * \Drupal), which makes controllers hard to unit test and hides dependencies.
 * Controllers should instead implement ContainerInjectionInterface (with
 * AutowireTrait) and explicitly declare their dependencies via constructor
 * injection.
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

    if ($parentClass->getName() !== 'Drupal\Core\Controller\ControllerBase') {
      return [];
    }

    return [
      RuleErrorBuilder::message(
        \sprintf(
          'Class %s extends ControllerBase. Use ContainerInjectionInterface with AutowireTrait instead, and inject dependencies via the constructor.',
          $classReflection->getName(),
        )
      )
        ->identifier('canvas.noControllerBase')
        ->build(),
    ];
  }

}

