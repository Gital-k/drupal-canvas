<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesFunction;

/**
 * Verifies PHPUnit coverage attributes reference existing symbols.
 *
 * #[CoversFunction], #[CoversClass], #[CoversMethod], #[UsesFunction], and
 * #[UsesClass] each take a symbol name as a plain string argument. PHPUnit
 * only validates that name when code coverage is actually being collected
 * (--strict-coverage / requireCoverageMetadata); the fast lint, unit, and
 * kernel CI lanes do not collect coverage, so a stale or misspelled symbol
 * name silently attributes coverage to the wrong — or a non-existent —
 * target, and nothing catches it.
 *
 * @implements Rule<InClassNode>
 */
final class CoverageAttributesReferenceExistingSymbolsRule implements Rule {

  public function __construct(
    private readonly ReflectionProvider $reflectionProvider,
  ) {}

  public function getNodeType(): string {
    return InClassNode::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    \assert($node instanceof InClassNode);

    $classReflection = $node->getClassReflection();
    if ($classReflection->isAnonymous()) {
      return [];
    }
    $reflection = $classReflection->getNativeReflection();
    $testClassName = $classReflection->getName();

    $errors = [];

    foreach ($reflection->getAttributes(CoversFunction::class) as $attribute) {
      $instance = $attribute->newInstance();
      \assert($instance instanceof CoversFunction);
      $error = $this->checkFunction($instance->functionName(), 'CoversFunction', $testClassName);
      if ($error !== NULL) {
        $errors[] = $error;
      }
    }
    foreach ($reflection->getAttributes(UsesFunction::class) as $attribute) {
      $instance = $attribute->newInstance();
      \assert($instance instanceof UsesFunction);
      $error = $this->checkFunction($instance->functionName(), 'UsesFunction', $testClassName);
      if ($error !== NULL) {
        $errors[] = $error;
      }
    }
    foreach ($reflection->getAttributes(CoversClass::class) as $attribute) {
      $instance = $attribute->newInstance();
      \assert($instance instanceof CoversClass);
      $error = $this->checkClass($instance->className(), 'CoversClass', $testClassName);
      if ($error !== NULL) {
        $errors[] = $error;
      }
    }
    foreach ($reflection->getAttributes(UsesClass::class) as $attribute) {
      $instance = $attribute->newInstance();
      \assert($instance instanceof UsesClass);
      $error = $this->checkClass($instance->className(), 'UsesClass', $testClassName);
      if ($error !== NULL) {
        $errors[] = $error;
      }
    }
    foreach ($reflection->getAttributes(CoversMethod::class) as $attribute) {
      $instance = $attribute->newInstance();
      \assert($instance instanceof CoversMethod);
      $error = $this->checkMethod($instance->className(), $instance->methodName(), $testClassName);
      if ($error !== NULL) {
        $errors[] = $error;
      }
    }

    return $errors;
  }

  /**
   * Checks that a #[CoversFunction]/#[UsesFunction] target exists.
   */
  private function checkFunction(string $functionName, string $attributeName, string $testClassName): ?RuleError {
    if ($this->reflectionProvider->hasFunction(new Name($functionName), NULL)) {
      return NULL;
    }
    return RuleErrorBuilder::message(\sprintf(
      "%s declares #[%s('%s')], but function %s() does not exist.",
      $testClassName,
      $attributeName,
      $functionName,
      $functionName,
    ))->identifier('canvas.coverageAttributeReferencesExistingSymbol')->build();
  }

  /**
   * Checks that a #[CoversClass]/#[UsesClass] target exists.
   */
  private function checkClass(string $className, string $attributeName, string $testClassName): ?RuleError {
    if ($this->reflectionProvider->hasClass($className)) {
      return NULL;
    }
    return RuleErrorBuilder::message(\sprintf(
      '%s declares #[%s(%s::class)], but class %s does not exist.',
      $testClassName,
      $attributeName,
      $className,
      $className,
    ))->identifier('canvas.coverageAttributeReferencesExistingSymbol')->build();
  }

  /**
   * Checks that a #[CoversMethod] target class and method exist.
   */
  private function checkMethod(string $className, string $methodName, string $testClassName): ?RuleError {
    if (!$this->reflectionProvider->hasClass($className)) {
      return RuleErrorBuilder::message(\sprintf(
        "%s declares #[CoversMethod(%s::class, '%s')], but class %s does not exist.",
        $testClassName,
        $className,
        $methodName,
        $className,
      ))->identifier('canvas.coverageAttributeReferencesExistingSymbol')->build();
    }
    if ($this->reflectionProvider->getClass($className)->hasMethod($methodName)) {
      return NULL;
    }
    return RuleErrorBuilder::message(\sprintf(
      "%s declares #[CoversMethod(%s::class, '%s')], but method %s::%s() does not exist.",
      $testClassName,
      $className,
      $methodName,
      $className,
      $methodName,
    ))->identifier('canvas.coverageAttributeReferencesExistingSymbol')->build();
  }

}
